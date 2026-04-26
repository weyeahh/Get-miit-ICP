import { sleep } from './time.js';

const activeLocks = new Set();

export async function acquireLock(lockPath, options = {}) {
  const wait = options.wait ?? true;
  const intervalMs = options.intervalMs ?? 25;

  for (;;) {
    if (!activeLocks.has(lockPath)) {
      activeLocks.add(lockPath);
      return new MemoryLock(lockPath);
    }
    if (!wait) {
      return null;
    }
    await sleep(intervalMs);
  }
}

class MemoryLock {
  constructor(lockPath) {
    this.lockPath = lockPath;
    this.released = false;
  }

  async release() {
    if (this.released) {
      return;
    }
    this.released = true;
    activeLocks.delete(this.lockPath);
  }
}
