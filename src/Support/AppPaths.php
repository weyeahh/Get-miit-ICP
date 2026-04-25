<?php

declare(strict_types=1);

namespace Miit\Support;

final class AppPaths
{
    public static function storagePath(string $suffix = ''): string
    {
        $base = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }

        return $suffix === '' ? $base : $base . '/' . ltrim($suffix, '/');
    }

    public static function ensureDir(string $path): string
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }
}
