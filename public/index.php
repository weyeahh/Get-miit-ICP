<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Miit\Http\JsonResponse;
use Miit\Service\MiitQueryService;

header('Content-Type: application/json; charset=utf-8');

$domain = isset($_GET['domain']) ? trim((string) $_GET['domain']) : '';

if ($domain === '') {
    JsonResponse::send([
        'success' => false,
        'error' => 'domain parameter is required',
    ], 400);
}

try {
    $service = new MiitQueryService();
    $detail = $service->queryDomainDetail($domain, isset($_GET['debug']) && $_GET['debug'] === '1');

    JsonResponse::send([
        'success' => true,
        'domain' => $domain,
        'data' => $detail,
    ]);
} catch (Throwable $e) {
    JsonResponse::send([
        'success' => false,
        'error' => $e->getMessage(),
    ], 502);
}
