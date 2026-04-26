import path from 'node:path';
import { acquireLock } from './fileLock.js';
import { sha1 } from './hash.js';
import { MiitException } from '../Exception/miitException.js';

export class FileMutex {
  constructor(name, directory) {
    this.name = name;
    this.directory = directory;
    this.lock = null;
  }

  lockPath() {
    return path.join(this.directory, `${sha1(this.name)}.lock`);
  }

  async acquire() {
    try {
      this.lock = await acquireLock(this.lockPath());
    } catch (error) {
      throw new MiitException('failed to acquire lock', '', { cause: error });
    }
  }

  async tryAcquire() {
    try {
      this.lock = await acquireLock(this.lockPath(), { wait: false });
      return this.lock !== null;
    } catch (error) {
      throw new MiitException('failed to open lock file', '', { cause: error });
    }
  }

  async release() {
    if (this.lock === null) {
      return;
    }
    const lock = this.lock;
    this.lock = null;
    await lock.release();
  }
}
