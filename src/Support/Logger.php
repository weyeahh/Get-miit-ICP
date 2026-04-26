<?php

declare(strict_types=1);

namespace Miit\Support;

final class Logger
{
    /** @param array<string, mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            $dir = AppPaths::ensureDir(AppPaths::storagePath('logs'), true);
            $file = $dir . '/app-' . date('Y-m-d') . '.log';
            $line = json_encode([
                'time' => date(DATE_ATOM),
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                return;
            }

            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            self::writeStderr($level, $message, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private static function writeStderr(string $level, string $message, array $context): void
    {
        try {
            $line = json_encode([
                'time' => date(DATE_ATOM),
                'level' => $level,
                'message' => $message,
                'context' => $context,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($line === false) {
                return;
            }

            @file_put_contents('php://stderr', $line . PHP_EOL);
        } catch (\Throwable) {
            // Intentionally swallow logging failures.
        }
    }
}
