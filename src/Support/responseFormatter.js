import { InternalErrorException } from '../Exception/miitException.js';

const REQUIRED_FIELDS = ['domain', 'unitName', 'mainLicence', 'serviceLicence', 'natureName', 'leaderName', 'updateRecordTime'];

export class ResponseFormatter {
  static successPayload(detail, { cache = 'miss' } = {}) {
    for (const field of REQUIRED_FIELDS) {
      if (typeof detail[field] !== 'string') {
        throw new InternalErrorException(`detail response missing required field: ${field}`, 'internal server error');
      }
    }

    return {
      code: 200,
      message: 'successful',
      cache,
      data: {
        Domain: detail.domain,
        UnitName: detail.unitName,
        MainLicence: detail.mainLicence,
        ServiceLicence: detail.serviceLicence,
        NatureName: detail.natureName,
        LeaderName: detail.leaderName,
        UpdateRecordTime: detail.updateRecordTime,
      },
    };
  }
}
