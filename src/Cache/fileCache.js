import { randomInt } from 'node:crypto';
import { readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from '../Support/appPaths.js';
import { acquireLock } from '../Support/fileLock.js';
import { sha1 } from '../Support/hash.js';
import { epochSeconds } from '../Support/time.js';
import { StorageException } from '../Exception/miitException.js';

export class FileCache {
  constructor(directory = null) {
    this.directory = directory ?? AppPaths.storagePath('cache');
    this.gcRunning = false;
  }

  async get(key) {
    await this.gc();
    await AppPaths.ensureDir(this.directory, true);
    const file = this.fileForKey(key);
    let raw;
    try {
      raw = await readFile(file, 'utf8');
    } catch (error) {
      if (error?.code === 'ENOENT') {
        return null;
      }
      throw new StorageException('failed to open cache file', 'service storage is not ready', { cause: error });
    }

    if (raw === '') {
      return null;
    }

    let payload;
    try {
      payload = JSON.parse(raw);
    } catch {
      return null;
    }

    const expiresAt = Number.parseInt(payload?.expires_at ?? 0, 10) || 0;
    if (expiresAt > 0 && expiresAt < epochSeconds()) {
      await rm(file, { force: true }).catch(() => {});
      return null;
    }

    return payload?.value !== null && typeof payload?.value === 'object' && !Array.isArray(payload.value) ? payload.value : null;
  }

  async set(key, value, ttlSeconds) {
    await this.gc();
    await AppPaths.ensureDir(this.directory, true);
    const file = this.fileForKey(key);
    const payload = {
      expires_at: epochSeconds() + Math.max(1, ttlSeconds),
      value,
    };

    let json;
    try {
      json = JSON.stringify(payload);
    } catch (error) {
      throw new StorageException('failed to encode cache payload', 'service storage is not ready', { cause: error });
    }

    const lock = await this.lockForFile(file);
    try {
      await writeAtomic(file, json);
    } finally {
      await lock.release();
    }
  }

  fileForKey(key) {
    return path.join(this.directory, `${sha1(key)}.json`);
  }

  async lockForFile(file) {
    try {
      return await acquireLock(`${file}.lock`);
    } catch (error) {
      throw new StorageException('failed to lock cache file', 'service storage is not ready', { cause: error });
    }
  }

  async gc() {
    if (this.gcRunning || randomInt(1, 51) !== 1) {
      return;
    }

    this.gcRunning = true;
    try {
      await AppPaths.ensureDir(this.directory, true);
      const { readdir } = await import('node:fs/promises');
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
          let deleteFile = false;
          let raw = '';
          try {
            raw = await readFile(file, 'utf8');
          } catch {
            deleteFile = true;
          }

          if (raw === '') {
            deleteFile = true;
          } else {
            try {
              const decoded = JSON.parse(raw);
              const expiresAt = Number.parseInt(decoded?.expires_at ?? 0, 10) || 0;
              deleteFile = expiresAt > 0 && expiresAt < now;
            } catch {
              deleteFile = true;
            }
          }

          if (deleteFile) {
            await rm(file, { force: true });
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

async function writeAtomic(file, content) {
  try {
    await writeFile(file, content, 'utf8');
  } catch (error) {
    throw new StorageException('failed to write complete cache file', 'service storage is not ready', { cause: error });
  }
}
