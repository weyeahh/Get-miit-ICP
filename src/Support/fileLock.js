import { mkdir, rmdir } from 'node:fs/promises';
import { sleep } from './time.js';

const LOCK_RETRY_MS = 50;
const STALE_LOCK_MS = 30000;

export async function acquireLock(lockPath, options = {}) {
  const wait = options.wait ?? true;
  const intervalMs = options.intervalMs ?? LOCK_RETRY_MS;
  const deadlineMs = options.deadlineMs ?? (wait ? Date.now() + 15000 : Date.now());

  const dir = lockPath + '.lockdir';
  for (;;) {
    try {
      await mkdir(dir, { recursive: false });
    } catch (error) {
      if (error?.code === 'EEXIST') {
        await recoverStaleLock(dir);
        if (!wait || Date.now() >= deadlineMs) {
          return null;
        }
        await sleep(intervalMs);
        continue;
      }
      throw error;
    }

    return new MkdirLock(dir);
  }
}

class MkdirLock {
  constructor(dir) {
    this.dir = dir;
    this.released = false;
  }

  async release() {
    if (this.released) {
      return;
    }
    this.released = true;
    await rmdir(this.dir).catch(() => {});
  }
}

async function recoverStaleLock(dir) {
  try {
    const { stat } = await import('node:fs/promises');
    const info = await stat(dir);
    if (Date.now() - info.birthtimeMs > STALE_LOCK_MS) {
      await rmdir(dir, { recursive: true }).catch(() => {});
    }
  } catch {
    // stat 失败说明目录已消失，忽略
  }
}
