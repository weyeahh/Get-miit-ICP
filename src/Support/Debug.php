<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Config\AppConfig;

final class Debug
{
    public static function log(bool $enabled, string $message): void
    {
        $config = new AppConfig();
        if (!$enabled || !$config->bool('debug.allow_query_toggle')) {
            return;
        }

        file_put_contents('php://stderr', 'debug: ' . $message . PHP_EOL);
    }
}
