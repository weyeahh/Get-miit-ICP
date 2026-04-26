<?php

declare(strict_types=1);

namespace Miit\Support;

final class Debug
{
    /** @param array<string, mixed> $context */
    public static function log(bool $enabled, string $message, array $context = []): void
    {
        if (!$enabled) {
            return;
        }

        Logger::debug($message, $context);

        $line = 'debug: ' . $message;
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            }
        }

        @file_put_contents('php://stderr', $line . PHP_EOL);
    }
}
