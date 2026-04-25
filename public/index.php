<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Miit\Cache\FileCache;
use Miit\Cache\QueryCache;
use Miit\Exception\RateLimitException;
use Miit\Exception\RecordNotFoundException;
use Miit\Exception\ValidationException;
use Miit\Http\JsonResponse;
use Miit\RateLimit\DomainQueryLock;
use Miit\RateLimit\FileRateLimiter;
use Miit\RateLimit\QueryGuard;
use Miit\Service\MiitQueryService;
use Miit\Support\ClientIp;
use Miit\Support\Logger;
use Miit\Support\ResponseFormatter;
use Miit\Validation\DomainNormalizer;

header('Content-Type: application/json; charset=utf-8');

$rawDomain = isset($_GET['domain']) ? (string) $_GET['domain'] : '';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$ip = ClientIp::detect();
$domain = '';
$mutex = null;

try {
    $normalizer = new DomainNormalizer();
    $queryCache = new QueryCache(new FileCache());
    $guard = new QueryGuard(new FileRateLimiter());
    $domainQueryLock = new DomainQueryLock();

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

    $guard->assertAllowed($ip, $domain);

    $mutex = $domainQueryLock->mutexFor($domain);
    if (!$mutex->tryAcquire()) {
        for ($i = 0; $i < 10; $i++) {
            usleep(200000);

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

        throw new RateLimitException('too many in-flight requests for the same domain');
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

    $service = new MiitQueryService();
    $detail = $service->queryDomainDetail($domain, $debug);
    $queryCache->putSuccess($domain, $detail);

    JsonResponse::send(ResponseFormatter::successPayload($detail));
} catch (ValidationException $e) {
    JsonResponse::send([
        'code' => 400,
        'message' => $e->getMessage(),
        'data' => null,
    ], 400);
} catch (RecordNotFoundException $e) {
    if ($domain !== '') {
        try {
            $queryCache->putMiss($domain);
        } catch (Throwable $cacheError) {
            Logger::error('failed to write miss cache', [
                'ip' => $ip,
                'domain' => $domain,
                'exception' => $cacheError::class,
                'detail' => $cacheError->getMessage(),
            ]);
        }
    }

    JsonResponse::send([
        'code' => 404,
        'message' => 'no ICP record found',
        'data' => [
            'domain' => $domain,
            'detail' => $e->getMessage(),
        ],
    ], 404);
} catch (RateLimitException $e) {
    Logger::warning('request blocked by rate limiter', [
        'ip' => $ip,
        'domain' => $domain,
        'detail' => $e->getMessage(),
    ]);

    JsonResponse::send([
        'code' => 429,
        'message' => 'too many requests',
        'data' => [
            'domain' => $domain,
        ],
    ], 429);
} catch (Throwable $e) {
    try {
        if (isset($guard) && $domain !== '') {
            $guard->markUpstreamFailure($domain);
        }
    } catch (Throwable $guardError) {
        Logger::error('failed to update upstream cooldown state', [
            'ip' => $ip,
            'domain' => $domain,
            'exception' => $guardError::class,
            'detail' => $guardError->getMessage(),
        ]);
    }

    Logger::error('upstream query failed', [
        'ip' => $ip,
        'domain' => $domain,
        'exception' => $e::class,
        'detail' => $e->getMessage(),
    ]);

    JsonResponse::send([
        'code' => 500,
        'message' => 'upstream query failed',
        'data' => [
            'domain' => $domain,
            'detail' => 'the upstream service rejected or failed the query',
        ],
    ], 500);
} finally {
    if ($mutex !== null) {
        $mutex->release();
    }
}
