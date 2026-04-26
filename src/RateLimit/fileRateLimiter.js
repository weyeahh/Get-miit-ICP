import { randomInt } from 'node:crypto';
import { readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from '../Support/appPaths.js';
import { acquireLock } from '../Support/fileLock.js';
import { sha1 } from '../Support/hash.js';
import { epochSeconds } from '../Support/time.js';
import { StorageException } from '../Exception/miitException.js';

export class FileRateLimiter {
  constructor(directory = null) {
    this.directory = directory ?? AppPaths.storagePath('ratelimit');
    this.gcRunning = false;
  }

  async hit(key, windowSeconds, limit) {
    await this.gc();
    await AppPaths.ensureDir(this.directory, true);
    const file = this.fileForKey(key);
    const lock = await this.lockForFile(file);
    try {
      const state = await this.readState(file);
      const next = this.nextWindowState(state, windowSeconds, epochSeconds());
      next.count = Number.parseInt(next.count ?? 0, 10) + 1;
      await this.writeState(file, next);
      return next.count <= limit;
    } finally {
      await lock.release();
    }
  }

  async setCooldown(key, ttlSeconds) {
    await this.gc();
    await AppPaths.ensureDir(this.directory, true);
    const file = this.fileForKey(`cooldown:${key}`);
    const lock = await this.lockForFile(file);
    try {
      await this.writeState(file, {
        cooldown_until: epochSeconds() + Math.max(1, ttlSeconds),
      });
    } finally {
      await lock.release();
    }
  }

  async inCooldown(key) {
    await AppPaths.ensureDir(this.directory, true);
    const state = await this.readState(this.fileForKey(`cooldown:${key}`));
    return (Number.parseInt(state.cooldown_until ?? 0, 10) || 0) > epochSeconds();
  }

  async consumeAll(rules) {
    await this.gc();
    await AppPaths.ensureDir(this.directory, true);
    const entries = rules.map((rule) => ({
      rule,
      file: this.fileForKey(rule.key),
      lock: null,
    })).sort((left, right) => left.file.localeCompare(right.file));

    try {
      for (const entry of entries) {
        entry.lock = await this.lockForFile(entry.file);
      }

      const now = epochSeconds();
      const states = [];
      for (const entry of entries) {
        const state = await this.readState(entry.file);
        const next = this.nextWindowState(state, entry.rule.window, now);
        const nextCount = Number.parseInt(next.count ?? 0, 10) + 1;
        if (nextCount > entry.rule.limit) {
          throw new StorageException('rate limit exceeded', 'rate limit exceeded');
        }
        next.count = nextCount;
        states.push(next);
      }

      for (let index = 0; index < entries.length; index++) {
        await this.writeState(entries[index].file, states[index]);
      }
    } finally {
      for (const entry of entries.reverse()) {
        if (entry.lock !== null) {
          await entry.lock.release();
        }
      }
    }
  }

  nextWindowState(state, windowSeconds, now) {
    const started = Number.parseInt(state.window_started_at ?? 0, 10) || 0;
    if (started + windowSeconds <= now) {
      return { window_started_at: now, count: 0 };
    }

    return { ...state };
  }

  async readState(file) {
    let raw;
    try {
      raw = await readFile(file, 'utf8');
    } catch (error) {
      if (error?.code === 'ENOENT') {
        return {};
      }
      throw new StorageException('failed to open rate limit file for read', 'service storage is not ready', { cause: error });
    }

    if (raw === '') {
      return {};
    }

    try {
      const decoded = JSON.parse(raw);
      return decoded !== null && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
    } catch {
      return {};
    }
  }

  async writeState(file, state) {
    let json;
    try {
      json = JSON.stringify(state);
    } catch (error) {
      throw new StorageException('failed to encode rate limit state', 'service storage is not ready', { cause: error });
    }

    try {
      await writeFile(file, json, 'utf8');
    } catch (error) {
      throw new StorageException('failed to write complete rate limit state', 'service storage is not ready', { cause: error });
    }
  }

  fileForKey(key) {
    return path.join(this.directory, `${sha1(key)}.json`);
  }

  async lockForFile(file) {
    try {
      return await acquireLock(`${file}.lock`);
    } catch (error) {
      throw new StorageException('failed to lock rate limit file', 'service storage is not ready', { cause: error });
    }
  }

  async gc() {
    if (this.gcRunning || randomInt(1, 51) !== 1) {
      return;
    }

    this.gcRunning = true;
    try {
      await AppPaths.ensureDir(this.directory, true);
      const now = epochSeconds();
      const entries = await readdir(this.directory, { withFileTypes: true });
      for (const entry of entries) {
        if (!entry.isFile() || !entry.name.endsWith('.json')) {
          continue;
        }

        const file = path.join(this.directory, entry.name);
        const lock = await acquireLock(`${file}.lock`, { wait: false });
        if (lock === null) {
          continue;
        }

        try {
          const state = await this.readState(file);
          const cooldownUntil = Number.parseInt(state.cooldown_until ?? 0, 10) || 0;
          const windowStart = Number.parseInt(state.window_started_at ?? 0, 10) || 0;
          const deleteFile = Object.keys(state).length === 0
            || (cooldownUntil > 0 && cooldownUntil < now)
            || (windowStart > 0 && (now - windowStart) > 3600);
          if (deleteFile) {
            await rm(file, { force: true }).catch(() => {});
          }
        } finally {
          await lock.release();
        }
      }
    } finally {
      this.gcRunning = false;
    }
  }
}
