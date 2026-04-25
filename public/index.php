<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Miit\Cache\FileCache;
use Miit\Cache\QueryCache;
use Miit\Config\AppConfig;
use Miit\Exception\EnvironmentException;
use Miit\Exception\InternalErrorException;
use Miit\Exception\RateLimitException;
use Miit\Exception\RecordNotFoundException;
use Miit\Exception\StorageException;
use Miit\Exception\UpstreamException;
use Miit\Exception\ValidationException;
use Miit\Http\JsonResponse;
use Miit\RateLimit\DomainQueryLock;
use Miit\RateLimit\FileRateLimiter;
use Miit\RateLimit\QueryGuard;
use Miit\Service\MiitQueryService;
use Miit\Support\ClientIp;
use Miit\Support\DetailSanitizer;
use Miit\Support\Logger;
use Miit\Support\ResponseFormatter;
use Miit\Validation\DomainNormalizer;

header('Content-Type: application/json; charset=utf-8');

$rawDomain = isset($_GET['domain']) ? (string) $_GET['domain'] : '';
$ip = ClientIp::detect();
$domain = '';
$mutex = null;

try {
    $config = new AppConfig();
    $normalizer = new DomainNormalizer();
    $queryCache = new QueryCache(new FileCache(), $config);
    $guard = new QueryGuard(new FileRateLimiter(), $config);
    $domainQueryLock = new DomainQueryLock();
    $debug = $config->bool('debug.allow_query_toggle') && isset($_GET['debug']) && $_GET['debug'] === '1';

    $domain = $normalizer->normalize($rawDomain);

    $cachedSuccess = $queryCache->getSuccess($domain);
    if ($cachedSuccess !== null) {
        JsonResponse::send(ResponseFormatter::successPayload($cachedSuccess));
    }

    $cachedMiss = $queryCache->getMiss($domain);
    if ($cachedMiss !== null) {
        JsonResponse::send([
            'code' => 404,
            'message' => 'no ICP record found',
            'data' => [
                'domain' => $domain,
                'detail' => 'no ICP record found for ' . $domain,
                'cached' => true,
            ],
        ], 404);
    }

    $mutex = $domainQueryLock->mutexFor($domain);
    if (!$mutex->tryAcquire()) {
        $deadline = microtime(true) + $config->int('ratelimit.domain_wait_timeout_seconds');
        $interval = max(50, $config->int('ratelimit.domain_wait_interval_milliseconds')) * 1000;
        while (microtime(true) < $deadline) {
            usleep($interval);

            $cachedSuccess = $queryCache->getSuccess($domain);
            if ($cachedSuccess !== null) {
                JsonResponse::send(ResponseFormatter::successPayload($cachedSuccess));
            }

            $cachedMiss = $queryCache->getMiss($domain);
            if ($cachedMiss !== null) {
                JsonResponse::send([
                    'code' => 404,
                    'message' => 'no ICP record found',
                    'data' => [
                        'domain' => $domain,
                        'detail' => 'no ICP record found for ' . $domain,
                        'cached' => true,
                    ],
                ], 404);
            }
        }

        throw new RateLimitException('too many in-flight requests for the same domain', 'too many requests');
    }

    $cachedSuccess = $queryCache->getSuccess($domain);
    if ($cachedSuccess !== null) {
        JsonResponse::send(ResponseFormatter::successPayload($cachedSuccess));
    }

    $cachedMiss = $queryCache->getMiss($domain);
    if ($cachedMiss !== null) {
        JsonResponse::send([
            'code' => 404,
            'message' => 'no ICP record found',
            'data' => [
                'domain' => $domain,
                'detail' => 'no ICP record found for ' . $domain,
                'cached' => true,
            ],
        ], 404);
    }

    $guard->assertAllowed($ip, $domain);

    $service = new MiitQueryService();
    $detail = $service->queryDomainDetail($domain, $debug);
    $queryCache->putSuccess($domain, $detail);

    JsonResponse::send(ResponseFormatter::successPayload($detail));
} catch (ValidationException $e) {
    JsonResponse::send([
        'code' => 400,
        'message' => $e->userMessage(),
        'data' => null,
    ], 400);
} catch (RecordNotFoundException $e) {
    if (isset($queryCache) && $domain !== '') {
        try {
            $queryCache->putMiss($domain);
        } catch (Throwable $cacheError) {
            Logger::error('failed to write miss cache', [
                'ip' => $ip,
                'domain' => $domain,
                'exception' => $cacheError::class,
                'detail' => DetailSanitizer::truncate($cacheError->getMessage(), $config ?? new AppConfig()),
            ]);
        }
    }

    JsonResponse::send([
        'code' => 404,
        'message' => 'no ICP record found',
        'data' => [
            'domain' => $domain,
            'detail' => $e->userMessage(),
        ],
    ], 404);
} catch (RateLimitException $e) {
    Logger::warning('request blocked by rate limiter', [
        'ip' => $ip,
        'domain' => $domain,
        'detail' => DetailSanitizer::truncate($e->getMessage(), new AppConfig()),
    ]);

    JsonResponse::send([
        'code' => 429,
        'message' => 'too many requests',
        'data' => [
            'domain' => $domain,
        ],
    ], 429);
} catch (StorageException|EnvironmentException $e) {
    Logger::error('local storage or environment failure', [
        'ip' => $ip,
        'domain' => $domain,
        'exception' => $e::class,
        'detail' => DetailSanitizer::truncate($e->getMessage(), new AppConfig()),
    ]);

    JsonResponse::send([
        'code' => 500,
        'message' => 'service environment is not ready',
        'data' => [
            'domain' => $domain,
            'detail' => $e->userMessage(),
        ],
    ], 500);
} catch (UpstreamException $e) {
    try {
        if (isset($guard) && $domain !== '') {
            $guard->markUpstreamFailure($domain);
        }
    } catch (Throwable $guardError) {
        Logger::error('failed to update upstream cooldown state', [
            'ip' => $ip,
            'domain' => $domain,
            'exception' => $guardError::class,
            'detail' => DetailSanitizer::truncate($guardError->getMessage(), new AppConfig()),
        ]);
    }

    Logger::error('upstream query failed', [
        'ip' => $ip,
        'domain' => $domain,
        'exception' => $e::class,
        'detail' => DetailSanitizer::truncate($e->getMessage(), new AppConfig()),
    ]);

    JsonResponse::send([
        'code' => 500,
        'message' => 'upstream query failed',
        'data' => [
            'domain' => $domain,
            'detail' => $e->userMessage(),
        ],
    ], 500);
} catch (Throwable $e) {
    Logger::error('internal failure', [
        'ip' => $ip,
        'domain' => $domain,
        'exception' => $e::class,
        'detail' => DetailSanitizer::truncate($e->getMessage(), new AppConfig()),
    ]);

    JsonResponse::send([
        'code' => 500,
        'message' => 'internal server error',
        'data' => [
            'domain' => $domain,
            'detail' => 'the service encountered an internal error',
        ],
    ], 500);
} finally {
    if ($mutex !== null) {
        $mutex->release();
    }
}
