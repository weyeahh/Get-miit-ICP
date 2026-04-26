import test from 'node:test';
import assert from 'node:assert/strict';
import { DomainNormalizer } from '../src/Validation/domainNormalizer.js';
import { ValidationException } from '../src/Exception/miitException.js';

test('normalizes trailing dot and case', () => {
  const normalizer = new DomainNormalizer();
  assert.equal(normalizer.normalize('Example.COM.'), 'example.com');
});

test('rejects invalid domain whitespace', () => {
  const normalizer = new DomainNormalizer();
  assert.throws(() => normalizer.normalize('example .com'), ValidationException);
});
