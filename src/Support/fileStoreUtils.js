import { readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { randomInt } from 'node:crypto';
import path from 'node:path';
import { AppPaths } from './appPaths.js';
import { acquireLock } from './fileLock.js';
import { sha1 } from './hash.js';
import { epochSeconds } from './time.js';
import { StorageException } from '../Exception/miitException.js';

export class FileStoreBase {
  constructor(directory) {
    this.directory = directory;
    this.dirEnsured = false;
    this.gcRunning = false;
  }

  async ensureDir() {
    if (!this.dirEnsured) {
      await AppPaths.ensureDir(this.directory, true);
      this.dirEnsured = true;
    }
  }

  fileForKey(key) {
    return path.join(this.directory, `${sha1(key)}.json`);
  }

  async lockForFile(file) {
    try {
      return await acquireLock(`${file}.lock`);
    } catch (error) {
      throw new StorageException('failed to lock storage file', 'service storage is not ready', { cause: error });
    }
  }

  async readJson(file) {
    let raw;
    try {
      raw = await readFile(file, 'utf8');
    } catch (error) {
      if (error?.code === 'ENOENT') {
        return null;
      }
      throw new StorageException('failed to read storage file', 'service storage is not ready', { cause: error });
    }

    if (raw === '') {
      return null;
    }

    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  async writeJson(file, data) {
    let json;
    try {
      json = JSON.stringify(data);
    } catch (error) {
      throw new StorageException('failed to encode storage payload', 'service storage is not ready', { cause: error });
    }

    try {
      await writeFile(file, json, 'utf8');
    } catch (error) {
      throw new StorageException('failed to write storage file', 'service storage is not ready', { cause: error });
    }
  }

  async removeExpired(file, enforceTTL) {
    const payload = await this.readJson(file);
    if (payload === null) {
      return null;
    }

    const expiresAt = Number.parseInt(payload?.expires_at ?? 0, 10) || 0;
    if (enforceTTL && expiresAt > 0 && expiresAt < epochSeconds()) {
      await rm(file, { force: true }).catch(() => {});
      return null;
    }

    return payload;
  }

  shouldDelete(_payload, _now) {
    return false;
  }

  async gc() {
    if (this.gcRunning || randomInt(1, 51) !== 1) {
      return;
    }

    this.gcRunning = true;
    try {
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
          if (this.shouldDelete(payload, now)) {
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
