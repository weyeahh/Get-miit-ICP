import { AppPaths } from '../Support/appPaths.js';
import { FileMutex } from '../Support/fileMutex.js';

export class DomainQueryLock {
  constructor(directory = null) {
    this.directory = directory ?? AppPaths.storagePath('locks');
  }

  async mutexFor(domain) {
    await AppPaths.ensureDir(this.directory, true);
    return new FileMutex(`domain-query:${domain}`, this.directory);
  }
}
