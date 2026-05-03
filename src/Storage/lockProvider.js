import path from 'node:path';
import { acquireLock } from '../Support/fileLock.js';
import { sha1 } from '../Support/hash.js';
import { AppPaths } from '../Support/appPaths.js';
import { MiitException } from '../Exception/miitException.js';

export function createLockProvider(config) {
  const backend = config.string('storage.backend');
  if (backend === 'redis') {
    return createRedisLockProvider(config);
  }
  return new FileLockProvider();
}

class FileLockProvider {
  constructor(directory) {
    this.directory = directory ?? AppPaths.storagePath('locks');
  }

  async createMutex(resource) {
    await AppPaths.ensureDir(this.directory);
    const lockPath = path.join(this.directory, `${sha1(resource)}.lock`);
    let lock = null;

    return {
      async acquire() {
        lock = await acquireLock(lockPath);
      },
      async tryAcquire() {
        lock = await acquireLock(lockPath, { wait: false });
        return lock !== null;
      },
      async release() {
        if (lock === null) {
          return;
        }
        const current = lock;
        lock = null;
        await current.release();
      },
    };
  }
}

async function createRedisLockProvider(config) {
  const { getRedisClient, RedisLockProvider } = await import('./redisBackend.js');
  const redis = await getRedisClient(config);
  return new RedisLockProvider(redis, config.string('storage.redis.key_prefix'));
}
