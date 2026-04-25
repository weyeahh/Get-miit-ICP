<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Cache\FileCache;
use Miit\Cache\QueryCache;
use Miit\Config\AppConfig;

final class QueryCacheVersionTest
{
    public static function run(): void
    {
        $dir = dirname(__DIR__) . '/storage/test-cache';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('failed to create test cache directory');
        }

        try {
            $cache = new QueryCache(new FileCache($dir), new AppConfig(['cache.schema_version' => 'test-v1']));
            $cache->putSuccess('example.com', ['domain' => 'example.com']);
            $value = $cache->getSuccess('example.com');
            if (!is_array($value) || ($value['domain'] ?? '') !== 'example.com') {
                throw new \RuntimeException('cache version test failed');
            }
        } finally {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
}
