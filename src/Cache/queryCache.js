import { localISOString } from '../Support/time.js';

export class QueryCache {
  constructor(cache, config) {
    this.cache = cache;
    this.config = config;
  }

  async getSuccess(domain) {
    return this.getDetail('success', domain);
  }

  async putSuccess(domain, detail) {
    return this.putDetail('success', domain, detail);
  }

  async getMiss(domain) {
    const payload = await this.readPayload('miss', domain);
    if (payload === null) {
      return null;
    }
    return { domain: payload.domain, cached_at: payload._cached_at ?? '', cache_expires_at: payload._cache_expires_at ?? '' };
  }

  async putMiss(domain) {
    const { ttl, now } = this.ttlContext('cache.miss_ttl');
    const payload = {
      _schema_version: this.schemaVersion(),
      _cached_at: localISOString(now),
      _cache_expires_at: localISOString(new Date(now.getTime() + ttl * 1000)),
      domain,
      cached: true,
    };
    await this.writePayload('miss', domain, payload, ttl);
  }

  async getStale(domain) {
    return this.getDetail('success', domain, true);
  }

  async getList(key) {
    return this.getDetail('list', key);
  }

  async putList(key, detail) {
    return this.putDetail('list', key, detail);
  }

  async getDetail(prefix, key, stale = false) {
    const payload = stale
      ? await this.cache.getStale?.(this.cacheKey(prefix, key)) ?? null
      : await this.readPayload(prefix, key);
    if (payload === null) {
      return null;
    }
    const detail = payload.detail;
    if (detail === null || typeof detail !== 'object' || Array.isArray(detail)) {
      return null;
    }
    return { detail, cached_at: payload._cached_at ?? '', cache_expires_at: payload._cache_expires_at ?? '' };
  }

  async putDetail(prefix, key, detail) {
    const { ttl, now } = this.ttlContext('cache.success_ttl');
    const payload = {
      _schema_version: this.schemaVersion(),
      _cached_at: localISOString(now),
      _cache_expires_at: localISOString(new Date(now.getTime() + ttl * 1000)),
      detail,
    };
    await this.writePayload(prefix, key, payload, ttl);
  }

  async readPayload(prefix, key) {
    const payload = await this.cache.get(this.cacheKey(prefix, key));
    if (payload === null) {
      return null;
    }
    if ((payload._schema_version ?? '') !== this.schemaVersion()) {
      return null;
    }
    return payload;
  }

  async writePayload(prefix, key, payload, ttl) {
    const cacheKey = this.cacheKey(prefix, key);
    if (prefix === 'miss' && typeof this.cache.setMiss === 'function') {
      await this.cache.setMiss(cacheKey, payload, ttl);
    } else if (prefix === 'list' && typeof this.cache.setList === 'function') {
      await this.cache.setList(cacheKey, payload, ttl);
    } else if (typeof this.cache.setSuccess === 'function') {
      await this.cache.setSuccess(cacheKey, payload, ttl);
    } else {
      await this.cache.set(cacheKey, payload, ttl);
    }
  }

  schemaVersion() {
    return this.config.string('cache.schema_version');
  }

  ttlContext(ttlKey) {
    const ttl = this.config.int(ttlKey);
    const now = new Date();
    return { ttl, now };
  }

  cacheKey(prefix, key) {
    return `${prefix}:${this.schemaVersion()}:${key}`;
  }
}
