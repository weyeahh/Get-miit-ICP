import { rm } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from '../Support/appPaths.js';
import { acquireLock } from '../Support/fileLock.js';
import { epochSeconds } from '../Support/time.js';
import { FileStoreBase } from '../Support/fileStoreUtils.js';

export class FileCache extends FileStoreBase {
  constructor(directory = null) {
    super(directory ?? AppPaths.storagePath('cache'));
  }

  async get(key) {
    await this.ensureDir();
    const file = this.fileForKey(key);
    const payload = await this.removeExpired(file, true);
    return this.extractValue(payload);
  }

  async getStale(key) {
    await this.ensureDir();
    const file = this.fileForKey(key);
    const payload = await this.readJson(file);
    return this.extractValue(payload);
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

  shouldDelete(payload, now) {
    if (payload === null) {
      return true;
    }
    const expiresAt = Number.parseInt(payload?.expires_at ?? 0, 10) || 0;
    return expiresAt > 0 && expiresAt < now;
  }

  extractValue(payload) {
    const value = payload?.value;
    return value !== null && typeof value === 'object' && !Array.isArray(value) ? value : null;
  }
}
