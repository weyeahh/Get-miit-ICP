<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Exception\InternalErrorException;

final class ResponseFormatter
{
    /** @param array<string, mixed> $detail */
    public static function successPayload(array $detail): array
    {
        foreach (['domain', 'unitName', 'mainLicence', 'serviceLicence', 'natureName', 'leaderName', 'updateRecordTime'] as $field) {
            if (!isset($detail[$field]) || !is_string($detail[$field]) || $detail[$field] === '') {
                throw new InternalErrorException('detail response missing required field: ' . $field, 'internal server error');
            }
        }

        return [
            'code' => 200,
            'message' => 'successful',
            'data' => [
                'Domain' => $detail['domain'],
                'UnitName' => $detail['unitName'],
                'MainLicence' => $detail['mainLicence'],
                'ServiceLicence' => $detail['serviceLicence'],
                'NatureName' => $detail['natureName'],
                'LeaderName' => $detail['leaderName'],
                'UpdateRecordTime' => $detail['updateRecordTime'],
            ],
        ];
    }
}
