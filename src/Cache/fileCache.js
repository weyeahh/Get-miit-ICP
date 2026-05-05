import { randomInt } from 'node:crypto';
import { readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from '../Support/appPaths.js';
import { acquireLock } from '../Support/fileLock.js';
import { epochSeconds } from '../Support/time.js';
import { StorageException } from '../Exception/miitException.js';
import { FileStoreBase } from '../Support/fileStoreUtils.js';

export class FileCache extends FileStoreBase {
  constructor(directory = null) {
    super(directory ?? AppPaths.storagePath('cache'));
    this.gcRunning = false;
  }

  async get(key) {
    await this.ensureDir();
    const file = this.fileForKey(key);
    const payload = await this.removeExpired(file, true);
    return payload?.value !== null && typeof payload?.value === 'object' && !Array.isArray(payload.value) ? payload.value : null;
  }

  async getStale(key) {
    await this.ensureDir();
    const file = this.fileForKey(key);
    const payload = await this.readJson(file);
    return payload?.value !== null && typeof payload?.value === 'object' && !Array.isArray(payload.value) ? payload.value : null;
  }

  async set(key, value, ttlSeconds) {
    await this.ensureDir();
    await this.gc();
    const file = this.fileForKey(key);
    const payload = {
      expires_at: epochSeconds() + Math.max(1, ttlSeconds),
      value,
    };

    const lock = await this.lockForFile(file);
    try {
      await this.writeJson(file, payload);
    } finally {
      await lock.release();
    }
  }

  async gc() {
    if (this.gcRunning || randomInt(1, 51) !== 1) {
      return;
    }

    this.gcRunning = true;
    try {
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
          const payload = await this.readJson(file);
          const expiresAt = Number.parseInt(payload?.expires_at ?? 0, 10) || 0;
          if (payload === null || (expiresAt > 0 && expiresAt < now)) {
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
