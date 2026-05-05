import { readFile, rm, writeFile } from 'node:fs/promises';
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
}
