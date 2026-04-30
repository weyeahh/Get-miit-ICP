export class DomainQueryLock {
  constructor(lockProvider) {
    this.lockProvider = lockProvider;
  }

  async mutexFor(domain) {
    return this.lockProvider.createMutex(`domain-query:${domain}`);
  }
}
