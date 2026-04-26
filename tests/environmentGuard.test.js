import test from 'node:test';
import assert from 'node:assert/strict';
import { EnvironmentGuard } from '../src/Support/environmentGuard.js';

test('environment guard accepts current Node runtime', () => {
  assert.doesNotThrow(() => EnvironmentGuard.assertRuntimeReady());
});
