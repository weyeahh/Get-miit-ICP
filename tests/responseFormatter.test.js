import test from 'node:test';
import assert from 'node:assert/strict';
import { InternalErrorException } from '../src/Exception/miitException.js';
import { ResponseFormatter } from '../src/Support/responseFormatter.js';

test('response formatter rejects missing required detail fields', () => {
  assert.throws(() => ResponseFormatter.successPayload({
    domain: 'example.com',
    unitName: 'Example',
  }), InternalErrorException);
});

test('response formatter maps MIIT fields exactly', () => {
  const payload = ResponseFormatter.successPayload({
    domain: 'example.com',
    unitName: 'Example Unit',
    mainLicence: 'main',
    serviceLicence: 'service',
    natureName: 'enterprise',
    leaderName: 'leader',
    updateRecordTime: '2026-04-26',
  });

  assert.deepEqual(payload, {
    code: 200,
    message: 'successful',
    cache: 'miss',
    data: {
      Domain: 'example.com',
      UnitName: 'Example Unit',
      MainLicence: 'main',
      ServiceLicence: 'service',
      NatureName: 'enterprise',
      LeaderName: 'leader',
      UpdateRecordTime: '2026-04-26',
    },
  });
});
