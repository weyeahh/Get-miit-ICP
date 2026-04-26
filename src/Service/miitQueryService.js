import { AuthApi } from '../Api/authApi.js';
import { CaptchaApi } from '../Api/captchaApi.js';
import { IcpApi } from '../Api/icpApi.js';
import { MiitClient } from '../Api/miitClient.js';
import { CaptchaSolver } from '../Captcha/captchaSolver.js';
import { MiitException, RecordNotFoundException, UpstreamException } from '../Exception/miitException.js';
import { Debug } from '../Support/debug.js';
import { epochSeconds, sleep } from '../Support/time.js';

const DETAIL_FIELDS = ['domain', 'unitName', 'mainLicence', 'serviceLicence', 'natureName', 'leaderName', 'updateRecordTime'];

const ID_FIELD_CANDIDATES = {
  mainId: ['mainId', 'mainID', 'main_id', 'ids.mainId', 'record.mainId', 'mainInfo.mainId'],
  domainId: ['domainId', 'domainID', 'domain_id', 'ids.domainId', 'record.domainId', 'domainInfo.domainId'],
  serviceId: ['serviceId', 'serviceID', 'service_id', 'ids.serviceId', 'record.serviceId', 'serviceInfo.serviceId'],
};

const DOMAIN_FIELD_CANDIDATES = ['domain', 'domainName', 'serviceDomain', 'websiteDomain'];

export class MiitQueryService {
  constructor(timeout = 15) {
    this.timeout = timeout;
  }

  async queryDomainDetail(domain, debug = false) {
    domain = String(domain).trim();
    if (domain === '') {
      throw new MiitException('domain is required');
    }

    const timestamp = epochSeconds();
    const client = new MiitClient(this.timeout);
    const authApi = new AuthApi(client);
    const captchaApi = new CaptchaApi(client);
    const icpApi = new IcpApi(client);
    const solver = new CaptchaSolver(client, captchaApi);

    await Debug.log(debug, `step=auth timestamp=${timestamp}`);
    const authResponse = await this.retryUpstream(() => authApi.auth(timestamp));
    await Debug.log(debug, `step=auth success=true expire=${String(authResponse.params?.expire ?? '')}`);

    const clientUid = CaptchaApi.newClientUid();
    await Debug.log(debug, `step=getCheckImagePoint clientUid=${clientUid}`);

    const challenge = await this.retryUpstream(() => captchaApi.getCheckImagePoint(clientUid));
    const params = challenge.params !== null && typeof challenge.params === 'object' ? challenge.params : {};
    const captchaUuid = String(params.uuid ?? '');
    const bigImage = String(params.bigImage ?? '');
    const smallImage = String(params.smallImage ?? '');
    const height = phpInt(params.height, -1);
    if (captchaUuid === '' || bigImage === '' || height < 0) {
      throw new UpstreamException('captcha challenge params missing', 'upstream query failed');
    }

    await Debug.log(debug, `step=getCheckImagePoint success=true captchaUUID=${captchaUuid} height=${height}`);

    const solved = await solver.solve(captchaUuid, bigImage, smallImage, height, debug);
    const checkResponse = solved.response;
    const solvedUuid = String(solved.solvedUuid ?? captchaUuid);
    const sign = String(checkResponse.params ?? '');
    if (sign === '') {
      throw new UpstreamException('checkImage response missing sign', 'upstream query failed');
    }

    client.setSign(sign);
    client.setUuid(solvedUuid);

    await Debug.log(debug, `step=query endpoint=icpAbbreviateInfo/queryByCondition unitName=${domain} serviceType=1`);
    const queryResponse = await this.retryUpstream(() => icpApi.queryByCondition(domain));
    if ((queryResponse.success ?? false) !== true || (queryResponse.code ?? 0) !== 200) {
      throw new UpstreamException(`queryByCondition rejected: code=${String(queryResponse.code ?? '')} msg=${String(queryResponse.msg ?? '')}`, 'upstream query failed');
    }

    const queryParams = queryResponse.params !== null && typeof queryResponse.params === 'object' && !Array.isArray(queryResponse.params) ? queryResponse.params : {};
    const list = Array.isArray(queryParams.list) ? queryParams.list : [];
    if (list.length === 0) {
      throw new RecordNotFoundException(`no ICP record found for ${domain}`);
    }

    await Debug.log(debug, 'step=queryByCondition success=true', {
      params_keys: this.keysOf(queryParams),
      list_count: list.length,
    });

    const item = await this.selectBestMatch(list, domain, debug);
    const identifiers = this.extractIdentifiers(item);
    const mainId = identifiers.mainId;
    const domainId = identifiers.domainId;
    const serviceId = identifiers.serviceId;

    await Debug.log(debug, 'step=queryByCondition raw_identifiers', {
      mainId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.mainId),
      domainId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.domainId),
      serviceId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.serviceId),
      mainId_type: typeof this.firstScalarValue(item, ID_FIELD_CANDIDATES.mainId),
      domainId_type: typeof this.firstScalarValue(item, ID_FIELD_CANDIDATES.domainId),
      serviceId_type: typeof this.firstScalarValue(item, ID_FIELD_CANDIDATES.serviceId),
      parsed: identifiers,
    });

    if (mainId <= 0 || domainId <= 0 || serviceId < 0) {
      const fallback = await this.fallbackToListDetail(item, debug, 'queryByCondition', 'missing_valid_identifiers');
      if (fallback !== null) {
        return fallback;
      }

      await Debug.log(debug, 'step=queryByCondition missing_valid_identifiers', {
        selected: this.summarizeCandidate(item, identifiers),
      });

      throw new UpstreamException(`queryByCondition response missing valid identifiers; keys=${this.keysOf(item).join(',')}`, 'upstream query failed');
    }

    await Debug.log(debug, `step=queryDetail endpoint=icpAbbreviateInfo/queryDetailByServiceIdAndDomainId mainId=${mainId} domainId=${domainId} serviceId=${serviceId}`);

    let detailResponse;
    try {
      detailResponse = await this.retryUpstream(() => icpApi.queryDetail(mainId, domainId, serviceId));
    } catch (error) {
      if (error instanceof UpstreamException) {
        const fallback = await this.fallbackToListDetail(item, debug, 'queryDetail', error.message);
        if (fallback !== null) {
          return fallback;
        }
      }
      throw error;
    }

    if ((detailResponse.success ?? false) !== true || (detailResponse.code ?? 0) !== 200) {
      const fallback = await this.fallbackToListDetail(
        item,
        debug,
        'queryDetail',
        `rejected code=${String(detailResponse.code ?? '')} msg=${String(detailResponse.msg ?? '')}`,
      );
      if (fallback !== null) {
        return fallback;
      }

      throw new UpstreamException(`detail response rejected: code=${String(detailResponse.code ?? '')} msg=${String(detailResponse.msg ?? '')}`, 'upstream query failed');
    }

    const detail = detailResponse.params;
    if (detail === null || typeof detail !== 'object' || Array.isArray(detail)) {
      const fallback = await this.fallbackToListDetail(item, debug, 'queryDetail', 'params_missing');
      if (fallback !== null) {
        return fallback;
      }

      throw new UpstreamException('detail response params missing', 'upstream query failed');
    }

    const normalizedDetail = this.detailFromListItem(detail);
    if (normalizedDetail !== null) {
      return normalizedDetail;
    }

    const fallback = await this.fallbackToListDetail(item, debug, 'queryDetail', 'required_fields_missing');
    if (fallback !== null) {
      return fallback;
    }

    throw new UpstreamException(`detail response missing required fields; keys=${this.keysOf(detail).join(',')}`, 'upstream query failed');
  }

  async selectBestMatch(list, domain, debug = false) {
    let fallback = null;
    let matchedCount = 0;
    const summaries = [];

    for (const candidate of list) {
      if (candidate === null || typeof candidate !== 'object' || Array.isArray(candidate)) {
        continue;
      }

      if (!this.candidateMatchesDomain(candidate, domain)) {
        continue;
      }

      matchedCount++;
      const identifiers = this.extractIdentifiers(candidate);
      if (summaries.length < 5) {
        summaries.push(this.summarizeCandidate(candidate, identifiers));
      }

      if (this.identifiersAreValid(identifiers)) {
        await Debug.log(debug, 'step=queryByCondition selected_match', {
          matched_count: matchedCount,
          selected: this.summarizeCandidate(candidate, identifiers),
        });

        return candidate;
      }

      if (fallback === null) {
        fallback = candidate;
      }
    }

    if (fallback !== null) {
      await Debug.log(debug, 'step=queryByCondition exact_matches_without_valid_identifiers', {
        matched_count: matchedCount,
        candidates: summaries,
      });

      return fallback;
    }

    await Debug.log(debug, 'step=queryByCondition no_exact_match', {
      domain,
      list_count: list.length,
      candidate_keys: this.candidateKeySamples(list),
    });

    throw new RecordNotFoundException(`no exact ICP record found for ${domain}`, false);
  }

  candidateMatchesDomain(candidate, domain) {
    const expected = String(domain).toLowerCase().replace(/\.+$/u, '');
    for (const field of DOMAIN_FIELD_CANDIDATES) {
      const value = this.valueAtPath(candidate, field);
      if (!isScalar(value)) {
        continue;
      }

      if (String(value).trim().toLowerCase().replace(/\.+$/u, '') === expected) {
        return true;
      }
    }

    return false;
  }

  extractIdentifiers(item) {
    return {
      mainId: this.extractPositiveInt(item, ID_FIELD_CANDIDATES.mainId),
      domainId: this.extractPositiveInt(item, ID_FIELD_CANDIDATES.domainId),
      serviceId: this.extractPositiveInt(item, ID_FIELD_CANDIDATES.serviceId),
    };
  }

  identifiersAreValid(identifiers) {
    return identifiers.mainId > 0 && identifiers.domainId > 0 && identifiers.serviceId > 0;
  }

  extractPositiveInt(item, paths) {
    for (const itemPath of paths) {
      const value = this.valueAtPath(item, itemPath);
      if (Number.isInteger(value)) {
        if (value > 0) {
          return value;
        }
        continue;
      }

      if (typeof value === 'number' && Number.isFinite(value) && value > 0 && Math.floor(value) === value) {
        return value;
      }

      if (typeof value === 'string' && /^[1-9][0-9]*$/u.test(value)) {
        return Number.parseInt(value, 10);
      }
    }

    return 0;
  }

  async fallbackToListDetail(item, debug, source, reason) {
    const detail = this.detailFromListItem(item);
    if (detail === null) {
      await Debug.log(debug, `step=${source} fallback_detail_failed`, {
        reason,
        missing_field: this.detailMissingField(item),
        detail_field_values: this.detailFieldValues(item),
      });
      return null;
    }

    await Debug.log(debug, `step=${source} fallback=list_item_detail`, {
      reason,
      keys: this.keysOf(item),
    });

    return detail;
  }

  detailFromListItem(item) {
    const detail = {};
    for (const field of DETAIL_FIELDS) {
      if (!Object.prototype.hasOwnProperty.call(item, field)) {
        return null;
      }

      const value = item[field];
      if (value !== null && typeof value === 'object') {
        return null;
      }

      detail[field] = String(value).trim();
    }

    return detail;
  }

  detailMissingField(item) {
    for (const field of DETAIL_FIELDS) {
      if (!Object.prototype.hasOwnProperty.call(item, field)) {
        return { field, reason: 'missing_key' };
      }

      const value = item[field];
      if (value !== null && typeof value === 'object') {
        return { field, reason: 'object_value', type: typeof value };
      }

      const trimmed = String(value).trim();
      if (trimmed === '') {
        return { field, reason: 'empty_string', raw_value: String(value) };
      }
    }

    return null;
  }

  detailFieldValues(item) {
    const values = {};
    for (const field of DETAIL_FIELDS) {
      const value = item[field];
      values[field] = {
        type: typeof value,
        value: value === null ? 'null' : value === undefined ? 'undefined' : String(value).substring(0, 80),
      };
    }
    return values;
  }

  summarizeCandidate(item, identifiers) {
    return {
      keys: this.keysOf(item),
      domain: this.firstScalarValue(item, DOMAIN_FIELD_CANDIDATES),
      mainId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.mainId),
      domainId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.domainId),
      serviceId_raw: this.firstScalarValue(item, ID_FIELD_CANDIDATES.serviceId),
      identifiers,
      list_detail_ready: this.detailFromListItem(item) !== null,
    };
  }

  firstScalarValue(item, paths) {
    for (const itemPath of paths) {
      const value = this.valueAtPath(item, itemPath);
      if (isScalar(value)) {
        return value;
      }
    }

    return null;
  }

  valueAtPath(item, itemPath) {
    let current = item;
    for (const segment of itemPath.split('.')) {
      if (current === null || typeof current !== 'object' || !Object.prototype.hasOwnProperty.call(current, segment)) {
        return null;
      }

      current = current[segment];
    }

    return current;
  }

  keysOf(item) {
    return Object.keys(item).slice(0, 40).map((key) => String(key));
  }

  candidateKeySamples(list) {
    const samples = [];
    for (const candidate of list) {
      if (candidate === null || typeof candidate !== 'object' || Array.isArray(candidate)) {
        continue;
      }

      samples.push(this.keysOf(candidate));
      if (samples.length >= 5) {
        break;
      }
    }

    return samples;
  }

  async retryUpstream(fn, maxRetries = 3, baseDelay = 500) {
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        return await fn();
      } catch (error) {
        if (attempt === maxRetries || !(error instanceof UpstreamException)) {
          throw error;
        }
        await sleep(baseDelay * Math.pow(2, attempt));
      }
    }
  }
}

function isScalar(value) {
  return ['string', 'number', 'boolean'].includes(typeof value);
}

function phpInt(value, defaultValue) {
  if (value === undefined || value === null) {
    return defaultValue;
  }

  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? 0 : parsed;
}
