<?php

declare(strict_types=1);

namespace Miit\Support;

final class ResponseFormatter
{
    /** @param array<string, mixed> $detail */
    public static function successPayload(array $detail): array
    {
        return [
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
        ];
    }
}
