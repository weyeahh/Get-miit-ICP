import { QueryCache } from '../Cache/queryCache.js';
import { AppConfig } from '../Config/appConfig.js';
import {
  EnvironmentException,
  RateLimitException,
  RecordNotFoundException,
  StorageException,
  UpstreamException,
  ValidationException,
} from '../Exception/miitException.js';
import { JsonResponse } from '../Http/jsonResponse.js';
import { DomainQueryLock } from '../RateLimit/domainQueryLock.js';
import { QueryGuard } from '../RateLimit/queryGuard.js';
import { createCacheStore } from '../Storage/cacheStore.js';
import { createLockProvider } from '../Storage/lockProvider.js';
import { createRateLimitStore } from '../Storage/rateLimitStore.js';
import { MiitQueryService } from '../Service/miitQueryService.js';
import { ClientIp } from '../Support/clientIp.js';
import { DetailSanitizer } from '../Support/detailSanitizer.js';
import { EnvironmentGuard } from '../Support/environmentGuard.js';
import { Logger } from '../Support/logger.js';
import { ResponseFormatter } from '../Support/responseFormatter.js';
import { localISOString, sleep } from '../Support/time.js';
import { DomainNormalizer } from '../Validation/domainNormalizer.js';

let cachedConfig = null;
let cachedCacheStore = null;
let cachedRateLimiter = null;
let cachedLockProvider = null;

function getConfig() {
  if (cachedConfig === null) {
    cachedConfig = new AppConfig();
  }
  return cachedConfig;
}

async function getCacheStore() {
  if (cachedCacheStore === null) {
    cachedCacheStore = await createCacheStore(getConfig());
  }
  return cachedCacheStore;
}

async function getRateLimiter() {
  if (cachedRateLimiter === null) {
    cachedRateLimiter = await createRateLimitStore(getConfig());
  }
  return cachedRateLimiter;
}

async function getLockProvider() {
  if (cachedLockProvider === null) {
    cachedLockProvider = await createLockProvider(getConfig());
  }
  return cachedLockProvider;
}

export function resetStores() {
  cachedConfig = null;
  cachedCacheStore = null;
  cachedRateLimiter = null;
  cachedLockProvider = null;
}

function buildRespond(response, start, context) {
  return (payload, status) => {
    const duration = `${Date.now() - start}ms`;
    if (payload !== null && typeof payload === 'object' && 'data' in payload) {
      const { data, ...rest } = payload;
      JsonResponse.send(response, { ...rest, duration, data }, status);
    } else {
      JsonResponse.send(response, { ...payload, duration }, status);
    }

    const code = payload?.code ?? 200;
    const cache = payload?.cache ?? '-';
    const httpStatus = status ?? 200;
    process.stdout.write(`[${localISOString()}] ${context.ip} ${context.queryKey} ${httpStatus} ${code} ${cache} ${duration}\n`);
  };
}

function checkAuth(request, respond) {
  const config = getConfig();
  if (!config.bool('auth.api_key_enabled')) {
    return true;
  }

  const expectedKey = config.string('auth.api_key');
  const headerKey = request.headers['x-api-key'] ?? '';
  const url = new URL(request.url, 'http://localhost');
  const queryKey = url.searchParams.get('api_key') ?? '';
  const requestKey = headerKey !== '' ? String(headerKey) : queryKey;
  if (requestKey === '' || requestKey !== expectedKey) {
    respond({
      code: 401,
      message: 'unauthorized',
      data: { domain: '', detail: 'invalid or missing API key' },
    }, 401);
    return false;
  }

  return true;
}

async function handleListQuery(keyword, respond, ip, debug) {
  const config = getConfig();
  const queryCache = new QueryCache(await getCacheStore(), config);
  const guard = new QueryGuard(await getRateLimiter(), config);
  const lockProvider = new DomainQueryLock(await getLockProvider());
  const cacheKey = `list:${keyword}`;

  const cachedList = await queryCache.getList(cacheKey);
  if (cachedList !== null) {
    respond(ResponseFormatter.listPayload(cachedList.detail, { cache: 'hit', cached_at: cachedList.cached_at, cache_expires_at: cachedList.cache_expires_at }));
    return;
  }

  const mutex = await lockProvider.mutexFor(cacheKey);
  if (!(await mutex.tryAcquire())) {
    const deadline = Date.now() + config.int('ratelimit.domain_wait_timeout_seconds') * 1000;
    const interval = Math.max(50, config.int('ratelimit.domain_wait_interval_milliseconds'));
    while (Date.now() < deadline) {
      await sleep(interval);
      const cachedListRetry = await queryCache.getList(cacheKey);
      if (cachedListRetry !== null) {
        respond(ResponseFormatter.listPayload(cachedListRetry.detail, { cache: 'hit', cached_at: cachedListRetry.cached_at, cache_expires_at: cachedListRetry.cache_expires_at }));
        return;
      }
    }

    throw new RateLimitException('too many in-flight requests for the same query', 'too many requests');
  }

  try {
    const cachedListAfterLock = await queryCache.getList(cacheKey);
    if (cachedListAfterLock !== null) {
      respond(ResponseFormatter.listPayload(cachedListAfterLock.detail, { cache: 'hit', cached_at: cachedListAfterLock.cached_at, cache_expires_at: cachedListAfterLock.cache_expires_at }));
      return;
    }

    await guard.assertAllowed(ip, keyword);

    const service = new MiitQueryService();
    const result = await service.queryList(keyword, debug);
    await queryCache.putList(cacheKey, result);

    if (result.unitName && result.mainLicence) {
      const altKey = `list:${cacheKey === `list:${result.unitName}` ? result.mainLicence : result.unitName}`;
      if (altKey !== cacheKey) {
        await queryCache.putList(altKey, result).catch((err) => {
          Logger.error('failed to write alt list cache', { detail: err?.message ?? '' }).catch(() => {});
        });
      }
    }

    if (Array.isArray(result.records)) {
      await Promise.all(result.records.map((record) => {
        const detail = {
          domain: record.domain,
          unitName: record.unitName,
          mainLicence: record.mainLicence,
          serviceLicence: record.serviceLicence,
          natureName: record.natureName,
          leaderName: record.leaderName,
          updateRecordTime: record.updateRecordTime,
        };
        return detail.domain && detail.unitName
          ? queryCache.putSuccess(detail.domain, detail).catch(() => {})
          : Promise.resolve();
      }));
    }

    respond(ResponseFormatter.listPayload(result));
  } finally {
    await mutex.release();
  }
}

async function handleDomainQuery(domain, respond, ip, debug, { matchByDomain = true } = {}) {
  const config = getConfig();
  const queryCache = new QueryCache(await getCacheStore(), config);
  const guard = new QueryGuard(await getRateLimiter(), config);
  const lockProvider = new DomainQueryLock(await getLockProvider());

  if (await sendCachedSuccess(respond, queryCache, domain)) {
    return null;
  }
  if (await sendCachedMiss(respond, queryCache, domain)) {
    return null;
  }

  const mutex = await lockProvider.mutexFor(domain);
  if (!(await mutex.tryAcquire())) {
    const deadline = Date.now() + config.int('ratelimit.domain_wait_timeout_seconds') * 1000;
    const interval = Math.max(50, config.int('ratelimit.domain_wait_interval_milliseconds'));
    while (Date.now() < deadline) {
      await sleep(interval);
      if (await sendCachedSuccess(respond, queryCache, domain)) {
        return null;
      }
      if (await sendCachedMiss(respond, queryCache, domain)) {
        return null;
      }
    }

    throw new RateLimitException('too many in-flight requests for the same domain', 'too many requests');
  }

  if (await sendCachedSuccess(respond, queryCache, domain)) {
    await mutex.release();
    return null;
  }
  if (await sendCachedMiss(respond, queryCache, domain)) {
    await mutex.release();
    return null;
  }

  await guard.assertAllowed(ip, domain);

  const service = new MiitQueryService();
  const detail = await service.queryDomainDetail(domain, debug, { matchByDomain });
  const payload = ResponseFormatter.successPayload(detail);
  await queryCache.putSuccess(detail.domain, detail);

  respond(payload);
  return { mutex, queryCache, guard };
}

export async function handleQuery(request, response) {
  const rawDomain = queryParamLast(request.url, 'domain') ?? '';
  const rawUnitName = queryParamLast(request.url, 'unitName') ?? '';
  const rawLicence = queryParamLast(request.url, 'licence') ?? '';
  const ip = ClientIp.detect(request);
  const start = Date.now();
  const queryKey = rawUnitName || rawLicence || rawDomain || '-';

  const respond = buildRespond(response, start, { ip, queryKey });
  let domain = '';
  let mutex = null;
  let queryCache = null;
  let guard = null;

  try {
    EnvironmentGuard.assertRuntimeReady();
    await EnvironmentGuard.assertSharpReady();

    if (!checkAuth(request, respond)) {
      return;
    }

    const debug = getConfig().bool('debug.enabled');

    if (rawUnitName !== '' || rawLicence !== '') {
      const keyword = rawUnitName || rawLicence;

      if (looksLikeDomain(keyword)) {
        respond({
          code: 400,
          message: 'domain format input is not allowed for unitName/licence, use the domain parameter instead',
          data: null,
        }, 400);
        return;
      }

      if (rawLicence !== '' && isServiceLicence(rawLicence)) {
        domain = rawLicence;
        const result = await handleDomainQuery(domain, respond, ip, debug, { matchByDomain: false });
        if (result !== null) {
          mutex = result.mutex;
          queryCache = result.queryCache;
          guard = result.guard;
        }
        return;
      }

      await handleListQuery(keyword, respond, ip, debug);
      return;
    }

    const normalizer = new DomainNormalizer();
    domain = normalizer.normalize(rawDomain);

    const result = await handleDomainQuery(domain, respond, ip, debug);
    if (result !== null) {
      mutex = result.mutex;
      queryCache = result.queryCache;
      guard = result.guard;
    }
  } catch (error) {
    await handleError(error, respond, {
      ip,
      domain,
      queryCache,
      guard,
      config: getConfig(),
    });
  } finally {
    if (mutex !== null) {
      await mutex.release();
    }
    Logger.cleanup().catch(() => {});
  }
}

async function sendCachedSuccess(respond, queryCache, domain) {
  const cached = await queryCache.getSuccess(domain);
  if (cached === null) {
    return false;
  }

  respond(ResponseFormatter.successPayload(cached.detail, { cache: 'hit', cached_at: cached.cached_at, cache_expires_at: cached.cache_expires_at }));
  return true;
}

async function sendCachedMiss(respond, queryCache, domain) {
  const cached = await queryCache.getMiss(domain);
  if (cached === null) {
    return false;
  }

  respond({
    code: 404,
    message: 'no ICP record found',
    cache: 'hit',
    cached_at: cached.cached_at,
    cache_expires_at: cached.cache_expires_at,
    data: {
      domain,
      detail: `no ICP record found for ${domain}`,
    },
  }, 404);
  return true;
}

async function handleError(error, respond, context) {
  if (error instanceof ValidationException) {
    respond({
      code: 400,
      message: error.userMessage(),
      data: null,
    }, 400);
    return;
  }

  if (error instanceof RecordNotFoundException) {
    if (context.queryCache !== null && context.domain !== '' && error.cacheable()) {
      try {
        await context.queryCache.putMiss(context.domain);
      } catch (cacheError) {
        await Logger.error('failed to write miss cache', {
          ip: context.ip,
          domain: context.domain,
          exception: cacheError?.name ?? 'Error',
          detail: DetailSanitizer.truncate(cacheError?.message ?? '', context.config),
        });
      }
    }

    respond({
      code: 404,
      message: 'no ICP record found',
      data: {
        domain: context.domain,
        detail: error.userMessage(),
      },
    }, 404);
    return;
  }

  if (error instanceof RateLimitException) {
    await Logger.warning('request blocked by rate limiter', {
      ip: context.ip,
      domain: context.domain,
      detail: DetailSanitizer.truncate(error.message, context.config),
    });

    respond({
      code: 429,
      message: 'too many requests',
      data: {
        domain: context.domain,
      },
    }, 429);
    return;
  }

  if (error instanceof StorageException || error instanceof EnvironmentException) {
    await Logger.error('local storage or environment failure', {
      ip: context.ip,
      domain: context.domain,
      exception: error.name,
      detail: DetailSanitizer.truncate(error.message, context.config),
    });

    respond({
      code: 500,
      message: 'service environment is not ready',
      data: {
        domain: context.domain,
        detail: error.userMessage(),
      },
    }, 500);
    return;
  }

  if (error instanceof UpstreamException) {
    try {
      if (context.guard !== null && context.domain !== '') {
        await context.guard.markUpstreamFailure(context.domain);
      }
    } catch (guardError) {
      await Logger.error('failed to update upstream cooldown state', {
        ip: context.ip,
        domain: context.domain,
        exception: guardError?.name ?? 'Error',
        detail: DetailSanitizer.truncate(guardError?.message ?? '', context.config),
      });
    }

    if (context.queryCache !== null && context.domain !== '') {
      const stale = await context.queryCache.getStale(context.domain);
      if (stale !== null) {
        await Logger.warning('returning stale cache due to upstream failure', {
          ip: context.ip,
          domain: context.domain,
          detail: DetailSanitizer.truncate(error.message, context.config),
        });

        const payload = ResponseFormatter.successPayload(stale.detail, { cache: 'hit', cached_at: stale.cached_at, cache_expires_at: stale.cache_expires_at });
        respond({
          ...payload,
          data: { ...payload.data, stale: true },
        });
        return;
      }
    }

    await Logger.error('upstream query failed', {
      ip: context.ip,
      domain: context.domain,
      exception: error.name,
      detail: DetailSanitizer.truncate(error.message, context.config),
    });

    respond({
      code: 500,
      message: 'upstream query failed',
      data: {
        domain: context.domain,
        detail: error.userMessage(),
      },
    }, 500);
    return;
  }

  await Logger.error('internal failure', {
    ip: context.ip,
    domain: context.domain,
    exception: error?.name ?? 'Error',
    detail: DetailSanitizer.truncate(error?.message ?? '', context.config),
  });

  respond({
    code: 500,
    message: 'internal server error',
    data: {
      domain: context.domain,
      detail: 'the service encountered an internal error',
    },
  }, 500);
}

function queryParamLast(requestUrl, name) {
  const url = new URL(requestUrl, 'http://localhost');
  return url.searchParams.get(name);
}

function looksLikeDomain(input) {
  const trimmed = String(input).trim();
  return /^[a-z0-9.-]+\.[a-z]{2,}$/iu.test(trimmed) && trimmed.includes('.');
}

function isServiceLicence(input) {
  return /号-\d+$/u.test(String(input).trim());
}
