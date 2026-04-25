<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Exception\EnvironmentException;

final class AppPaths
{
    public static function storagePath(string $suffix = ''): string
    {
        $base = dirname(__DIR__, 2) . '/storage';
        self::ensureDir($base, true);

        return $suffix === '' ? $base : $base . '/' . ltrim($suffix, '/');
    }

    public static function ensureDir(string $path, bool $requireWritable = false): string
    {
        if (!is_dir($path)) {
            if (!mkdir($path, 0777, true) && !is_dir($path)) {
                throw new EnvironmentException('failed to create directory: ' . $path, 'service environment is not ready');
            }
        }

        if ($requireWritable && !is_writable($path)) {
            throw new EnvironmentException('directory is not writable: ' . $path, 'service environment is not ready');
        }

        return $path;
    }
}
