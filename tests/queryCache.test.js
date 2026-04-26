import test from 'node:test';
import assert from 'node:assert/strict';
import { rm } from 'node:fs/promises';
import { FileCache } from '../src/Cache/fileCache.js';
import { QueryCache } from '../src/Cache/queryCache.js';
import { AppConfig } from '../src/Config/appConfig.js';
import { AppPaths } from '../src/Support/appPaths.js';

test('query cache stores schema-versioned success payloads', async () => {
  const dir = AppPaths.storagePath(`test-cache-node-${process.pid}-${Date.now()}`);
  await rm(dir, { recursive: true, force: true });

  try {
    const cache = new QueryCache(new FileCache(dir), new AppConfig({ 'cache.schema_version': 'test-v1' }));
    await cache.putSuccess('example.com', { domain: 'example.com' });
    const value = await cache.getSuccess('example.com');
    assert.equal(value.domain, 'example.com');

    const stale = new QueryCache(new FileCache(dir), new AppConfig({ 'cache.schema_version': 'test-v2' }));
    assert.equal(await stale.getSuccess('example.com'), null);
  } finally {
    await rm(dir, { recursive: true, force: true }).catch(() => {});
  }
});
