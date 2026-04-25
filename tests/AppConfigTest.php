<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Config\AppConfig;

final class AppConfigTest
{
    public static function run(): void
    {
        $config = new AppConfig([
            'ratelimit.domain_wait_timeout_seconds' => -10,
            'ratelimit.global_qps' => 0,
            'log.max_detail_length' => 999999,
        ]);

        if ($config->int('ratelimit.domain_wait_timeout_seconds') !== 0) {
            throw new \RuntimeException('wait timeout clamp failed');
        }

        if ($config->int('ratelimit.global_qps') !== 1) {
            throw new \RuntimeException('global qps clamp failed');
        }

        if ($config->int('log.max_detail_length') !== 4096) {
            throw new \RuntimeException('log length clamp failed');
        }
    }
}
