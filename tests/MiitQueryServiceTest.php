<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Service\MiitQueryService;
use ReflectionMethod;

final class MiitQueryServiceTest
{
    public static function run(): void
    {
        self::selectBestMatchSkipsIdentifierlessCandidate();
        self::extractIdentifiersAcceptsSupportedVariants();
        self::fallbackDetailUsesCompleteListItem();
    }

    private static function selectBestMatchSkipsIdentifierlessCandidate(): void
    {
        $service = new MiitQueryService();
        $selected = self::invoke($service, 'selectBestMatch', [[
            [
                'domain' => 'example.com',
                'unitName' => 'first',
                'mainLicence' => 'main-a',
                'serviceLicence' => 'service-a',
                'natureName' => 'nature',
                'leaderName' => 'leader',
                'updateRecordTime' => '2026-04-26',
            ],
            [
                'domain' => 'example.com',
                'mainId' => '101',
                'domainID' => 202,
                'service_id' => '303',
            ],
        ], 'example.com', false]);

        if (($selected['mainId'] ?? '') !== '101') {
            throw new \RuntimeException('query match should prefer candidate with valid identifiers');
        }
    }

    private static function extractIdentifiersAcceptsSupportedVariants(): void
    {
        $service = new MiitQueryService();
        $identifiers = self::invoke($service, 'extractIdentifiers', [[
            'ids' => [
                'mainId' => '11',
                'domainId' => 22,
                'serviceId' => '33',
            ],
        ]]);

        if ($identifiers !== ['mainId' => 11, 'domainId' => 22, 'serviceId' => 33]) {
            throw new \RuntimeException('identifier variant extraction failed');
        }
    }

    private static function fallbackDetailUsesCompleteListItem(): void
    {
        $service = new MiitQueryService();
        $detail = self::invoke($service, 'fallbackToListDetail', [[
            'domain' => ' example.com ',
            'unitName' => ' Example Unit ',
            'mainLicence' => ' main-licence ',
            'serviceLicence' => ' service-licence ',
            'natureName' => ' enterprise ',
            'leaderName' => ' leader ',
            'updateRecordTime' => ' 2026-04-26 ',
        ], false, 'queryByCondition', 'test']);

        if (!is_array($detail) || $detail['domain'] !== 'example.com' || $detail['unitName'] !== 'Example Unit') {
            throw new \RuntimeException('list detail fallback should normalize complete list item fields');
        }

        $missing = self::invoke($service, 'fallbackToListDetail', [[
            'domain' => 'example.com',
        ], false, 'queryByCondition', 'test']);

        if ($missing !== null) {
            throw new \RuntimeException('list detail fallback should reject incomplete list item');
        }
    }

    /** @param list<mixed> $args */
    private static function invoke(MiitQueryService $service, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($service, $method);

        return $reflection->invokeArgs($service, $args);
    }
}
