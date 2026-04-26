import test from 'node:test';
import assert from 'node:assert/strict';
import { MiitQueryService } from '../src/Service/miitQueryService.js';

test('selectBestMatch skips identifierless exact candidate', async () => {
  const service = new MiitQueryService();
  const selected = await service.selectBestMatch([
    {
      domain: 'example.com',
      unitName: 'first',
      mainLicence: 'main-a',
      serviceLicence: 'service-a',
      natureName: 'nature',
      leaderName: 'leader',
      updateRecordTime: '2026-04-26',
    },
    {
      domain: 'example.com',
      mainId: '101',
      domainID: 202,
      service_id: '303',
    },
  ], 'example.com', false);

  assert.equal(selected.mainId, '101');
});

test('extractIdentifiers accepts supported variants', () => {
  const service = new MiitQueryService();
  const identifiers = service.extractIdentifiers({
    ids: {
      mainId: '11',
      domainId: 22,
      serviceId: '33',
    },
  });

  assert.deepEqual(identifiers, { mainId: 11, domainId: 22, serviceId: 33 });
});

test('fallback detail uses complete list item', async () => {
  const service = new MiitQueryService();
  const detail = await service.fallbackToListDetail({
    domain: ' example.com ',
    unitName: ' Example Unit ',
    mainLicence: ' main-licence ',
    serviceLicence: ' service-licence ',
    natureName: ' enterprise ',
    leaderName: ' leader ',
    updateRecordTime: ' 2026-04-26 ',
  }, false, 'queryByCondition', 'test');

  assert.equal(detail.domain, 'example.com');
  assert.equal(detail.unitName, 'Example Unit');

  const missing = await service.fallbackToListDetail({ domain: 'example.com' }, false, 'queryByCondition', 'test');
  assert.equal(missing, null);
});
