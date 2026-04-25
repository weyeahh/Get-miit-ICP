<?php

declare(strict_types=1);

namespace Miit\Support;

final class Debug
{
    public static function log(bool $enabled, string $message): void
    {
        if (!$enabled) {
            return;
        }

        file_put_contents('php://stderr', 'debug: ' . $message . PHP_EOL);
    }
}
