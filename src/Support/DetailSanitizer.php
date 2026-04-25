<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Config\AppConfig;

final class DetailSanitizer
{
    public static function truncate(string $detail, AppConfig $config): string
    {
        $max = max(64, $config->int('log.max_detail_length'));
        if (strlen($detail) <= $max) {
            return $detail;
        }

        return substr($detail, 0, $max) . '...';
    }
}
