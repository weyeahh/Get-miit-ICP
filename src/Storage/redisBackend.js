import { StorageException } from '../Exception/miitException.js';
import { epochSeconds } from '../Support/time.js';

let clientPromise = null;

export async function getRedisClient(config) {
  if (clientPromise !== null) {
    return clientPromise;
  }

  clientPromise = (async () => {
    const { Redis } = await import('ioredis');
    const url = config.string('storage.redis.url');
    const timeout = Math.max(1000, config.int('storage.redis.connect_timeout'));

    const client = new Redis(url, {
      connectTimeout: timeout,
      maxRetriesPerRequest: 3,
      enableOfflineQueue: false,
      retryStrategy(times) {
        if (times > 3) {
          return null;
        }
        return Math.min(times * 200, 2000);
      },
      lazyConnect: false,
    });

    client.on('error', (error) => {
      process.stderr.write(`[redis] connection error: ${error.message}\n`);
    });

    await client.ping();
    return client;
  })();

  try {
    return await clientPromise;
  } catch (error) {
    clientPromise = null;
    throw error;
  }
}

export async function closeRedisClient() {
  if (clientPromise === null) {
    return;
  }
  try {
    const client = await clientPromise;
    await client.quit();
  } catch {
    // best-effort
  } finally {
    clientPromise = null;
  }
}

export class RedisCache {
  constructor(redis, prefix, config) {
    this.redis = redis;
    this.prefix = prefix;
    this.staleSuccessTtl = config ? Math.max(1, config.int('cache.success_stale_ttl')) : 604800;
    this.staleMissTtl = config ? Math.max(1, config.int('cache.miss_stale_ttl')) : 86400;
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
      if (payload?.expires_at !== undefined && payload.expires_at < epochSeconds()) {
        return null;
      }
      return payload?.value !== null && typeof payload?.value === 'object' && !Array.isArray(payload.value) ? payload.value : null;
    } catch {
      return null;
    }
  }

  async getStale(key) {
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

  async set(key, value, ttlSeconds, staleTtlSeconds) {
    try {
      const redisTtl = Math.max(1, staleTtlSeconds || ttlSeconds);
      const payload = JSON.stringify({
        value,
        expires_at: epochSeconds() + Math.max(1, ttlSeconds),
      });
      await this.redis.setex(this.cacheKey(key), redisTtl, payload);
    } catch (error) {
      throw new StorageException('failed to write redis cache', 'service storage is not ready', { cause: error });
    }
  }

  async setSuccess(key, value, ttlSeconds) {
    await this.set(key, value, ttlSeconds, this.staleSuccessTtl);
  }

  async setMiss(key, value, ttlSeconds) {
    await this.set(key, value, ttlSeconds, this.staleMissTtl);
  }
}

const RATE_LIMIT_LUA = `
  local failed = nil
  for i = 1, #KEYS do
    local ws = tonumber(ARGV[i * 3 - 2])
    local limit = tonumber(ARGV[i * 3 - 1])
    local ttl = tonumber(ARGV[i * 3])
    local cur = tonumber(redis.call('GET', KEYS[i]) or '0')
    if cur == 0 or ws == 0 then
      cur = 0
    end
    cur = cur + 1
    redis.call('SET', KEYS[i], cur, 'EX', ttl)
    if cur > limit then
      failed = KEYS[i]
      break
    end
  end
  if failed then
    for i = 1, #KEYS do
      local v = tonumber(redis.call('GET', KEYS[i]) or '0')
      if v > 0 then
        redis.call('DECR', KEYS[i])
      end
    end
    return failed
  end
  return 'OK'
`;

export class RedisRateLimiter {
  constructor(redis, prefix) {
    this.redis = redis;
    this.prefix = prefix;
  }

  cooldownKey(key) {
    return `${this.prefix}cooldown:${key}`;
  }

  windowKey(key, windowStart) {
    return `${this.prefix}rl:${key}:${windowStart}`;
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
    const keys = [];
    const argv = [];
    const now = epochSeconds();

    for (const rule of rules) {
      const window = Math.max(1, rule.window);
      const windowStart = Math.floor(now / window) * window;
      keys.push(this.windowKey(rule.key, windowStart));
      argv.push(String(windowStart));
      argv.push(String(rule.limit));
      argv.push(String(window));
    }

    try {
      const result = await this.redis.eval(RATE_LIMIT_LUA, keys.length, ...keys, ...argv);
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

const LOCK_RELEASE_LUA = `
  if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
  end
  return 0
`;

const LOCK_TTL_LUA = `
  if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('PEXPIRE', KEYS[1], ARGV[2])
  end
  return 0
`;

export class RedisLockProvider {
  constructor(redis, prefix) {
    this.redis = redis;
    this.prefix = prefix;
  }

  lockKey(resource) {
    return `${this.prefix}lock:${resource}`;
  }

  createMutex(resource) {
    const redis = this.redis;
    const key = this.lockKey(resource);
    const token = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const ttlMs = 60000;
    const acquireDeadlineMs = 15000;
    let acquired = false;
    let watchdogTimer = null;

    const startWatchdog = () => {
      const extendMs = Math.floor(ttlMs / 2);
      watchdogTimer = setInterval(async () => {
        try {
          await redis.eval(LOCK_TTL_LUA, 1, key, token, String(ttlMs));
        } catch {
          // best-effort; lock will expire naturally
        }
      }, extendMs);
      if (watchdogTimer.unref) {
        watchdogTimer.unref();
      }
    };

    const stopWatchdog = () => {
      if (watchdogTimer !== null) {
        clearInterval(watchdogTimer);
        watchdogTimer = null;
      }
    };

    return {
      async acquire() {
        const deadline = Date.now() + acquireDeadlineMs;
        for (;;) {
          const result = await redis.set(key, token, 'PX', ttlMs, 'NX');
          if (result === 'OK') {
            acquired = true;
            startWatchdog();
            return;
          }
          if (Date.now() >= deadline) {
            throw new StorageException('lock acquisition timed out', 'service storage is not ready');
          }
          await new Promise((resolve) => setTimeout(resolve, 50));
        }
      },

      async tryAcquire() {
        try {
          const result = await redis.set(key, token, 'PX', ttlMs, 'NX');
          if (result === 'OK') {
            acquired = true;
            startWatchdog();
            return true;
          }
          return false;
        } catch {
          return false;
        }
      },

      async release() {
        if (!acquired) {
          return;
        }
        acquired = false;
        stopWatchdog();
        try {
          await redis.eval(LOCK_RELEASE_LUA, 1, key, token);
        } catch (error) {
          process.stderr.write(`[redis] lock release failed for ${key}: ${error.message}\n`);
        }
      },
    };
  }
}
