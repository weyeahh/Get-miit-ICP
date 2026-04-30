const DEFAULTS = {
  cache: {
    schema_version: 'v1',
    success_ttl: 86400,
    miss_ttl: 1800,
    success_stale_ttl: 604800,
    miss_stale_ttl: 86400,
  },
  ratelimit: {
    global_qps: 5,
    ip_per_minute: 60,
    domain_per_window: 10,
    domain_window_seconds: 120,
    domain_cooldown_seconds: 60,
    global_cooldown_seconds: 10,
    domain_wait_timeout_seconds: 3,
    domain_wait_interval_milliseconds: 250,
  },
  auth: {
    api_key_enabled: false,
    api_key: '',
  },
  debug: {
    enabled: false,
    store_captcha_samples: false,
  },
  log: {
    max_detail_length: 512,
  },
  storage: {
    backend: 'file',
    redis: {
      url: 'redis://127.0.0.1:6379',
      key_prefix: 'miit:',
      connect_timeout: 3000,
    },
  },
};

const ENV_MAP = new Map([
  ['MIIT_CACHE_SCHEMA_VERSION', 'cache.schema_version'],
  ['MIIT_CACHE_SUCCESS_TTL', 'cache.success_ttl'],
  ['MIIT_CACHE_MISS_TTL', 'cache.miss_ttl'],
  ['MIIT_CACHE_SUCCESS_STALE_TTL', 'cache.success_stale_ttl'],
  ['MIIT_CACHE_MISS_STALE_TTL', 'cache.miss_stale_ttl'],
  ['MIIT_RATE_LIMIT_GLOBAL_QPS', 'ratelimit.global_qps'],
  ['MIIT_RATE_LIMIT_IP_PER_MINUTE', 'ratelimit.ip_per_minute'],
  ['MIIT_RATE_LIMIT_DOMAIN_PER_WINDOW', 'ratelimit.domain_per_window'],
  ['MIIT_RATE_LIMIT_DOMAIN_WINDOW_SECONDS', 'ratelimit.domain_window_seconds'],
  ['MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS', 'ratelimit.domain_cooldown_seconds'],
  ['MIIT_RATE_LIMIT_GLOBAL_COOLDOWN_SECONDS', 'ratelimit.global_cooldown_seconds'],
  ['MIIT_RATE_LIMIT_DOMAIN_WAIT_TIMEOUT_SECONDS', 'ratelimit.domain_wait_timeout_seconds'],
  ['MIIT_RATE_LIMIT_DOMAIN_WAIT_INTERVAL_MILLISECONDS', 'ratelimit.domain_wait_interval_milliseconds'],
  ['MIIT_DEBUG_ENABLED', 'debug.enabled'],
  ['MIIT_DEBUG_STORE_CAPTCHA_SAMPLES', 'debug.store_captcha_samples'],
  ['MIIT_API_KEY_ENABLED', 'auth.api_key_enabled'],
  ['MIIT_API_KEY', 'auth.api_key'],
  ['MIIT_LOG_MAX_DETAIL_LENGTH', 'log.max_detail_length'],
  ['MIIT_STORAGE_BACKEND', 'storage.backend'],
  ['MIIT_STORAGE_REDIS_URL', 'storage.redis.url'],
  ['MIIT_STORAGE_REDIS_KEY_PREFIX', 'storage.redis.key_prefix'],
  ['MIIT_STORAGE_REDIS_CONNECT_TIMEOUT', 'storage.redis.connect_timeout'],
]);

export class AppConfig {
  constructor(overrides = {}) {
    this.values = flatten(DEFAULTS);
    for (const [key, value] of this.envOverrides()) {
      this.values.set(key, value);
    }
    for (const [key, value] of Object.entries(overrides)) {
      this.values.set(key, value);
    }
  }

  int(key) {
    const value = Number.parseInt(this.values.get(key) ?? 0, 10) || 0;
    switch (key) {
      case 'cache.success_ttl':
        return clamp(value, 60, 604800);
      case 'cache.miss_ttl':
        return clamp(value, 30, 86400);
      case 'cache.success_stale_ttl':
        return clamp(value, 300, 2592000);
      case 'cache.miss_stale_ttl':
        return clamp(value, 60, 604800);
      case 'ratelimit.global_qps':
        return clamp(value, 1, 1000);
      case 'ratelimit.ip_per_minute':
        return clamp(value, 1, 10000);
      case 'ratelimit.domain_per_window':
        return clamp(value, 1, 1000);
      case 'ratelimit.domain_window_seconds':
        return clamp(value, 1, 86400);
      case 'ratelimit.domain_cooldown_seconds':
        return clamp(value, 1, 3600);
      case 'ratelimit.global_cooldown_seconds':
        return clamp(value, 1, 3600);
      case 'ratelimit.domain_wait_timeout_seconds':
        return clamp(value, 0, 10);
      case 'ratelimit.domain_wait_interval_milliseconds':
        return clamp(value, 10, 1000);
      case 'log.max_detail_length':
        return clamp(value, 64, 4096);
      default:
        return value;
    }
  }

  bool(key) {
    return Boolean(this.values.get(key) ?? false);
  }

  string(key) {
    return String(this.values.get(key) ?? '');
  }

  envOverrides() {
    const overrides = new Map();
    for (const [env, key] of ENV_MAP) {
      const value = process.env[env];
      if (value === undefined || value === '') {
        continue;
      }

      const current = this.values.get(key);
      if (typeof current === 'boolean') {
        overrides.set(key, ['1', 'true', 'yes', 'on'].includes(value.toLowerCase()));
      } else if (Number.isInteger(current)) {
        overrides.set(key, Number.parseInt(value, 10) || 0);
      } else {
        overrides.set(key, value);
      }
    }

    return overrides;
  }
}

function flatten(values, prefix = '', result = new Map()) {
  for (const [key, value] of Object.entries(values)) {
    const fullKey = prefix === '' ? key : `${prefix}.${key}`;
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      flatten(value, fullKey, result);
      continue;
    }
    result.set(fullKey, value);
  }

  return result;
}

function clamp(value, low, high) {
  return Math.max(low, Math.min(high, value));
}
