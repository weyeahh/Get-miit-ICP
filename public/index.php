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
        'code' => 200,
        'message' => 'successful',
        'data' => [
            'Domain' => (string) ($detail['domain'] ?? ''),
            'UnitName' => (string) ($detail['unitName'] ?? ''),
            'MainLicence' => (string) ($detail['mainLicence'] ?? ''),
            'ServiceLicence' => (string) ($detail['serviceLicence'] ?? ''),
            'NatureName' => (string) ($detail['natureName'] ?? ''),
            'LeaderName' => (string) ($detail['leaderName'] ?? ''),
            'UpdateRecordTime' => (string) ($detail['updateRecordTime'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    JsonResponse::send([
        'success' => false,
        'error' => $e->getMessage(),
    ], 502);
}
