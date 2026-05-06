import path from 'node:path';
import { AppPaths } from '../Support/appPaths.js';
import { epochSeconds } from '../Support/time.js';
import { StorageException } from '../Exception/miitException.js';
import { FileStoreBase } from '../Support/fileStoreUtils.js';

export class FileRateLimiter extends FileStoreBase {
  constructor(directory = null) {
    super(directory ?? AppPaths.storagePath('ratelimit'));
  }

  async hit(key, windowSeconds, limit) {
    await this.ensureDir();
    await this.gc();
    const file = this.fileForKey(key);
    const lock = await this.lockForFile(file);
    try {
      const state = await this.readState(file);
      const next = this.nextWindowState(state, windowSeconds, epochSeconds());
      next.count = Number.parseInt(next.count ?? 0, 10) + 1;
      await this.writeJson(file, next);
      return next.count <= limit;
    } finally {
      await lock.release();
    }
  }

  async setCooldown(key, ttlSeconds) {
    await this.ensureDir();
    const file = this.fileForKey(`cooldown:${key}`);
    const lock = await this.lockForFile(file);
    try {
      await this.writeJson(file, {
        cooldown_until: epochSeconds() + Math.max(1, ttlSeconds),
      });
    } finally {
      await lock.release();
    }
  }

  async inCooldown(key) {
    await this.ensureDir();
    const state = await this.readState(this.fileForKey(`cooldown:${key}`));
    return (Number.parseInt(state.cooldown_until ?? 0, 10) || 0) > epochSeconds();
  }

  async consumeAll(rules) {
    await this.ensureDir();
    await this.gc();
    const entries = rules.map((rule) => ({
      rule,
      file: this.fileForKey(rule.key),
      lock: null,
    })).sort((left, right) => left.file.localeCompare(right.file));

    try {
      await Promise.all(entries.map(async (entry) => {
        entry.lock = await this.lockForFile(entry.file);
      }));

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

      await Promise.all(entries.map((entry, i) => this.writeJson(entry.file, states[i])));
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
    const state = await this.readJson(file);
    return state !== null && typeof state === 'object' && !Array.isArray(state) ? state : {};
  }

  shouldDelete(payload, now) {
    if (payload === null) {
      return true;
    }
    const cooldownUntil = Number.parseInt(payload.cooldown_until ?? 0, 10) || 0;
    const windowStart = Number.parseInt(payload.window_started_at ?? 0, 10) || 0;
    return Object.keys(payload).length === 0
      || (cooldownUntil > 0 && cooldownUntil < now)
      || (windowStart > 0 && (now - windowStart) > 3600);
  }
}
