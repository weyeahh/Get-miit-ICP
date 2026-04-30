import { StorageException } from '../Exception/miitException.js';

let sharedClient = null;

export async function getRedisClient(config) {
  if (sharedClient !== null) {
    return sharedClient;
  }

  const { Redis } = await import('ioredis');
  const url = config.string('storage.redis.url');
  const timeout = Math.max(1000, config.int('storage.redis.connect_timeout'));

  sharedClient = new Redis(url, {
    connectTimeout: timeout,
    maxRetriesPerRequest: 3,
    retryStrategy(times) {
      if (times > 3) {
        return null;
      }
      return Math.min(times * 200, 2000);
    },
    lazyConnect: false,
  });

  await sharedClient.ping();
  return sharedClient;
}

export class RedisCache {
  constructor(redis, prefix) {
    this.redis = redis;
    this.prefix = prefix;
  }

  cacheKey(key) {
    return `${this.prefix}cache:${key}`;
  }

  async get(key) {
    try {
      const raw = await this.redis.get(this.cacheKey(key));
      if (raw === null) {
        return null;
      }
      const payload = JSON.parse(raw);
      return payload?.value !== null && typeof payload?.value === 'object' && !Array.isArray(payload.value) ? payload.value : null;
    } catch {
      return null;
    }
  }

  async getStale(key) {
    return this.get(key);
  }

  async set(key, value, ttlSeconds) {
    try {
      const payload = JSON.stringify({ value });
      await this.redis.setex(this.cacheKey(key), Math.max(1, ttlSeconds), payload);
    } catch (error) {
      throw new StorageException('failed to write redis cache', 'service storage is not ready', { cause: error });
    }
  }
}

export class RedisRateLimiter {
  constructor(redis, prefix) {
    this.redis = redis;
    this.prefix = prefix;
  }

  cooldownKey(key) {
    return `${this.prefix}cooldown:${key}`;
  }

  windowKey(key) {
    return `${this.prefix}rl:${key}`;
  }

  async inCooldown(key) {
    try {
      const exists = await this.redis.exists(this.cooldownKey(key));
      return exists === 1;
    } catch {
      return false;
    }
  }

  async setCooldown(key, ttlSeconds) {
    try {
      await this.redis.setex(this.cooldownKey(key), Math.max(1, ttlSeconds), '1');
    } catch (error) {
      throw new StorageException('failed to set redis cooldown', 'service storage is not ready', { cause: error });
    }
  }

  async consumeAll(rules) {
    const now = Math.floor(Date.now() / 1000);
    const lua = `
      local failed = nil
      for i = 1, #KEYS do
        local count = redis.call('INCR', KEYS[i])
        if count == 1 then
          redis.call('EXPIRE', KEYS[i], ARGV[i * 2])
        end
        local limit = tonumber(ARGV[i * 2 - 1])
        if count > limit then
          failed = KEYS[i]
          break
        end
      end
      if failed then
        for i = 1, #KEYS do
          redis.call('DECR', KEYS[i])
        end
        return failed
      end
      return 'OK'
    `;

    const keys = [];
    const argv = [];
    for (const rule of rules) {
      keys.push(this.windowKey(`${rule.key}:${now}`));
      argv.push(String(rule.limit));
      argv.push(String(Math.max(1, rule.window)));
    }

    try {
      const result = await this.redis.eval(lua, keys.length, ...keys, ...argv);
      if (result !== 'OK') {
        throw new StorageException('rate limit exceeded', 'rate limit exceeded');
      }
    } catch (error) {
      if (error instanceof StorageException) {
        throw error;
      }
      throw new StorageException('failed to check redis rate limit', 'service storage is not ready', { cause: error });
    }
  }
}

export class RedisLockProvider {
  constructor(redis, prefix) {
    this.redis = redis;
    this.prefix = prefix;
  }

  lockKey(resource) {
    return `${this.prefix}lock:${resource}`;
  }

  createMutex(resource) {
    const key = this.lockKey(resource);
    const token = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const ttl = 15;
    let acquired = false;

    return {
      async acquire() {
        for (;;) {
          const result = await this._trySet(key, token, ttl);
          if (result) {
            acquired = true;
            return;
          }
          await new Promise((resolve) => setTimeout(resolve, 50));
        }
      },

      async tryAcquire() {
        const result = await this._trySet(key, token, ttl);
        acquired = result;
        return result;
      },

      async release() {
        if (!acquired) {
          return;
        }
        acquired = false;
        const lua = `
          if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
          end
          return 0
        `;
        await this.redis.eval(lua, 1, key, token).catch(() => {});
      },
    };
  }

  async _trySet(key, token, ttl) {
    try {
      const result = await this.redis.set(key, token, 'PX', ttl * 1000, 'NX');
      return result === 'OK';
    } catch {
      return false;
    }
  }
}
