import { localISOString } from '../Support/time.js';

export class QueryCache {
  constructor(cache, config) {
    this.cache = cache;
    this.config = config;
  }

  async getSuccess(domain) {
    const payload = await this.cache.get(this.key('success', domain));
    if (payload === null) {
      return null;
    }
    if ((payload._schema_version ?? '') !== this.config.string('cache.schema_version')) {
      return null;
    }
    const detail = payload.detail;
    if (detail === null || typeof detail !== 'object' || Array.isArray(detail)) {
      return null;
    }
    return { detail, cached_at: payload._cached_at ?? '', cache_expires_at: payload._cache_expires_at ?? '' };
  }

  async putSuccess(domain, detail) {
    const ttl = this.config.int('cache.success_ttl');
    const now = new Date();
    const payload = {
      _schema_version: this.config.string('cache.schema_version'),
      _cached_at: localISOString(now),
      _cache_expires_at: localISOString(new Date(now.getTime() + ttl * 1000)),
      detail,
    };
    if (typeof this.cache.setSuccess === 'function') {
      await this.cache.setSuccess(this.key('success', domain), payload, ttl);
    } else {
      await this.cache.set(this.key('success', domain), payload, ttl);
    }
  }

  async getMiss(domain) {
    const payload = await this.cache.get(this.key('miss', domain));
    if (payload === null) {
      return null;
    }

    if ((payload._schema_version ?? '') !== this.config.string('cache.schema_version')) {
      return null;
    }
    return { domain: payload.domain, cached_at: payload._cached_at ?? '', cache_expires_at: payload._cache_expires_at ?? '' };
  }

  async putMiss(domain) {
    const ttl = this.config.int('cache.miss_ttl');
    const now = new Date();
    const payload = {
      _schema_version: this.config.string('cache.schema_version'),
      _cached_at: localISOString(now),
      _cache_expires_at: localISOString(new Date(now.getTime() + ttl * 1000)),
      domain,
      cached: true,
    };
    if (typeof this.cache.setMiss === 'function') {
      await this.cache.setMiss(this.key('miss', domain), payload, ttl);
    } else {
      await this.cache.set(this.key('miss', domain), payload, ttl);
    }
  }

  async getStale(domain) {
    const payload = await this.cache.getStale?.(this.key('success', domain)) ?? null;
    if (payload === null) {
      return null;
    }
    if ((payload._schema_version ?? '') !== this.config.string('cache.schema_version')) {
      return null;
    }
    const detail = payload.detail;
    if (detail === null || typeof detail !== 'object' || Array.isArray(detail)) {
      return null;
    }
    return { detail, cached_at: payload._cached_at ?? '', cache_expires_at: payload._cache_expires_at ?? '' };
  }

  key(prefix, domain) {
    return `${prefix}:${this.config.string('cache.schema_version')}:${domain}`;
  }
}
