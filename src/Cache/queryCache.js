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
    return detail !== null && typeof detail === 'object' && !Array.isArray(detail) ? detail : null;
  }

  async putSuccess(domain, detail) {
    await this.cache.set(this.key('success', domain), {
      _schema_version: this.config.string('cache.schema_version'),
      detail,
    }, this.config.int('cache.success_ttl'));
  }

  async getMiss(domain) {
    const payload = await this.cache.get(this.key('miss', domain));
    if (payload === null) {
      return null;
    }

    return (payload._schema_version ?? '') === this.config.string('cache.schema_version') ? payload : null;
  }

  async putMiss(domain) {
    await this.cache.set(this.key('miss', domain), {
      _schema_version: this.config.string('cache.schema_version'),
      domain,
      cached: true,
    }, this.config.int('cache.miss_ttl'));
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
    return detail !== null && typeof detail === 'object' && !Array.isArray(detail) ? detail : null;
  }

  key(prefix, domain) {
    return `${prefix}:${this.config.string('cache.schema_version')}:${domain}`;
  }
}
