import { FileCache } from '../Cache/fileCache.js';

export function createCacheStore(config) {
  const backend = config.string('storage.backend');
  if (backend === 'redis') {
    return createRedisCache(config);
  }
  return new FileCache();
}

async function createRedisCache(config) {
  const { getRedisClient, RedisCache } = await import('./redisBackend.js');
  const redis = await getRedisClient(config);
  return new RedisCache(redis, config.string('storage.redis.key_prefix'), config);
}
