<?php

declare(strict_types=1);

namespace Miit\Cache;

use Miit\Exception\StorageException;
use Miit\Exception\MiitException;
use Miit\Support\AppPaths;

final class FileCache
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = AppPaths::ensureDir($directory ?? AppPaths::storagePath('cache'), true);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        $this->gc();
        $file = $this->directory . '/' . sha1($key) . '.json';
        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new StorageException('failed to open cache file', 'service storage is not ready');
        }

        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new StorageException('failed to lock cache file for read', 'service storage is not ready');
        }

        try {
            $raw = stream_get_contents($handle);
            if (!is_string($raw) || $raw === '') {
                return null;
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                return null;
            }

            $expiresAt = (int) ($payload['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < time()) {
                @unlink($file);
                return null;
            }

            return is_array($payload['value'] ?? null) ? $payload['value'] : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $this->gc();
        $file = $this->directory . '/' . sha1($key) . '.json';
        $payload = [
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new StorageException('failed to encode cache payload', 'service storage is not ready');
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new StorageException('failed to open cache file', 'service storage is not ready');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException('failed to lock cache file', 'service storage is not ready');
        }

        try {
            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new StorageException('failed to truncate cache file', 'service storage is not ready');
            }

            $written = fwrite($handle, $json);
            if ($written === false || $written !== strlen($json)) {
                throw new StorageException('failed to write complete cache file', 'service storage is not ready');
            }

            if (!fflush($handle)) {
                throw new StorageException('failed to flush cache file', 'service storage is not ready');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function gc(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }

        $now = time();
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                @unlink($file);
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                @unlink($file);
                continue;
            }

            $expiresAt = (int) ($decoded['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt < $now) {
                @unlink($file);
            }
        }
    }
}
