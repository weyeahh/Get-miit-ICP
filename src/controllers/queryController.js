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
import { sleep } from '../Support/time.js';
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

export async function handleQuery(request, response) {
  const rawDomain = queryParamLast(request.url, 'domain') ?? '';
  const ip = ClientIp.detect(request);
  let domain = '';
  let mutex = null;
  let queryCache = null;
  let guard = null;

  try {
    const config = getConfig();
    EnvironmentGuard.assertRuntimeReady();
    await EnvironmentGuard.assertSharpReady();

    if (config.bool('auth.api_key_enabled')) {
      const expectedKey = config.string('auth.api_key');
      const headerKey = readHeader(request, 'x-api-key');
      const queryKey = queryParamLast(request.url, 'api_key') ?? '';
      const requestKey = headerKey !== '' ? headerKey : queryKey;
      if (requestKey === '' || requestKey !== expectedKey) {
        JsonResponse.send(response, {
          code: 401,
          message: 'unauthorized',
          data: { domain: '', detail: 'invalid or missing API key' },
        }, 401);
        return;
      }
    }

    const normalizer = new DomainNormalizer();
    queryCache = new QueryCache(await getCacheStore(), config);
    guard = new QueryGuard(await getRateLimiter(), config);
    const domainQueryLock = new DomainQueryLock(await getLockProvider());
    const debug = config.bool('debug.enabled');

    domain = normalizer.normalize(rawDomain);

    if (await sendCachedSuccess(response, queryCache, domain)) {
      return;
    }
    if (await sendCachedMiss(response, queryCache, domain)) {
      return;
    }

    mutex = await domainQueryLock.mutexFor(domain);
    if (!(await mutex.tryAcquire())) {
      const deadline = Date.now() + config.int('ratelimit.domain_wait_timeout_seconds') * 1000;
      const interval = Math.max(50, config.int('ratelimit.domain_wait_interval_milliseconds'));
      while (Date.now() < deadline) {
        await sleep(interval);
        if (await sendCachedSuccess(response, queryCache, domain)) {
          return;
        }
        if (await sendCachedMiss(response, queryCache, domain)) {
          return;
        }
      }

      throw new RateLimitException('too many in-flight requests for the same domain', 'too many requests');
    }

    if (await sendCachedSuccess(response, queryCache, domain)) {
      return;
    }
    if (await sendCachedMiss(response, queryCache, domain)) {
      return;
    }

    await guard.assertAllowed(ip, domain);

    const service = new MiitQueryService();
    const detail = await service.queryDomainDetail(domain, debug);
    const payload = ResponseFormatter.successPayload(detail);
    await queryCache.putSuccess(domain, detail);

    JsonResponse.send(response, payload);
  } catch (error) {
    await handleError(error, response, {
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

async function sendCachedSuccess(response, queryCache, domain) {
  const cached = await queryCache.getSuccess(domain);
  if (cached === null) {
    return false;
  }

  const payload = ResponseFormatter.successPayload(cached.detail, { cache: 'hit', cached_at: cached.cached_at });
  JsonResponse.send(response, payload);
  return true;
}

async function sendCachedMiss(response, queryCache, domain) {
  const cached = await queryCache.getMiss(domain);
  if (cached === null) {
    return false;
  }

  JsonResponse.send(response, {
    code: 404,
    message: 'no ICP record found',
    cache: 'hit',
    cached_at: cached.cached_at,
    data: {
      domain,
      detail: `no ICP record found for ${domain}`,
    },
  }, 404);
  return true;
}

async function handleError(error, response, context) {
  if (error instanceof ValidationException) {
    JsonResponse.send(response, {
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

    JsonResponse.send(response, {
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

    JsonResponse.send(response, {
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

    JsonResponse.send(response, {
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

        const payload = ResponseFormatter.successPayload(stale.detail, { cache: 'hit', cached_at: stale.cached_at });
        JsonResponse.send(response, {
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

    JsonResponse.send(response, {
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

  JsonResponse.send(response, {
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
  const values = url.searchParams.getAll(name);
  return values.length === 0 ? null : values[values.length - 1];
}

function readHeader(request, name) {
  const key = name.toLowerCase();
  for (const [header, value] of Object.entries(request.headers)) {
    if (header.toLowerCase() === key) {
      return String(value);
    }
  }

  return '';
}
