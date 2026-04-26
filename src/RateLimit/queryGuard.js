import { RateLimitException, StorageException } from '../Exception/miitException.js';

export class QueryGuard {
  constructor(limiter, config) {
    this.limiter = limiter;
    this.config = config;
  }

  async assertAllowed(ip, domain) {
    if (await this.limiter.inCooldown(`domain:${domain}`)) {
      throw new RateLimitException('domain is temporarily cooling down');
    }

    if (await this.limiter.inCooldown('global')) {
      throw new RateLimitException('service is temporarily cooling down');
    }

    try {
      await this.limiter.consumeAll([
        { key: `domain:${domain}`, window: this.config.int('ratelimit.domain_window_seconds'), limit: this.config.int('ratelimit.domain_per_window') },
        { key: 'global:qps', window: 1, limit: this.config.int('ratelimit.global_qps') },
        { key: `ip:${ip}`, window: 60, limit: this.config.int('ratelimit.ip_per_minute') },
      ]);
    } catch (error) {
      if (error instanceof StorageException && error.userMessage() === 'rate limit exceeded') {
        throw new RateLimitException('request rate exceeded', 'too many requests', { cause: error });
      }
      throw error;
    }
  }

  async markUpstreamFailure(domain) {
    await this.limiter.setCooldown(`domain:${domain}`, this.config.int('ratelimit.domain_cooldown_seconds'));
    await this.limiter.setCooldown('global', this.config.int('ratelimit.global_cooldown_seconds'));
  }
}
