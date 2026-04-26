import test from 'node:test';
import assert from 'node:assert/strict';
import { RecordNotFoundException } from '../src/Exception/miitException.js';

test('record not found keeps cacheable flag', () => {
  assert.equal(new RecordNotFoundException('a', true).cacheable(), true);
  assert.equal(new RecordNotFoundException('b', false).cacheable(), false);
});
