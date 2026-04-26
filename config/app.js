export default {
  cache: {
    schema_version: 'v1',
    success_ttl: 86400,
    miss_ttl: 1800,
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
};
