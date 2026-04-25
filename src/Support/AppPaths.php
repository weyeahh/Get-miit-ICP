<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Exception\MiitException;

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
                throw new MiitException('failed to create directory: ' . $path);
            }
        }

        if ($requireWritable && !is_writable($path)) {
            throw new MiitException('directory is not writable: ' . $path);
        }

        return $path;
    }
}
