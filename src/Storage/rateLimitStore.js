import { FileRateLimiter } from '../RateLimit/fileRateLimiter.js';

export function createRateLimitStore(config) {
  const backend = config.string('storage.backend');
  if (backend === 'redis') {
    return createRedisRateLimiter(config);
  }
  return new FileRateLimiter();
}

async function createRedisRateLimiter(config) {
  const { getRedisClient, RedisRateLimiter } = await import('./redisBackend.js');
  const redis = await getRedisClient(config);
  return new RedisRateLimiter(redis, config.string('storage.redis.key_prefix'));
}
