import test from 'node:test';
import assert from 'node:assert/strict';
import { AppConfig } from '../src/Config/appConfig.js';

test('clamps integer configuration boundaries', () => {
  const config = new AppConfig({
    'ratelimit.domain_wait_timeout_seconds': -10,
    'ratelimit.global_qps': 0,
    'log.max_detail_length': 999999,
  });

  assert.equal(config.int('ratelimit.domain_wait_timeout_seconds'), 0);
  assert.equal(config.int('ratelimit.global_qps'), 1);
  assert.equal(config.int('log.max_detail_length'), 4096);
});
