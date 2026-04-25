<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Config\AppConfig;

final class DetailSanitizer
{
    public static function truncate(string $detail, AppConfig $config): string
    {
        $max = max(64, $config->int('log.max_detail_length'));
        if (mb_strlen($detail, 'UTF-8') <= $max) {
            return $detail;
        }

        return mb_substr($detail, 0, $max, 'UTF-8') . '...';
    }
}
