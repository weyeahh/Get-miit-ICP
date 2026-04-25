<?php

declare(strict_types=1);

namespace Miit\Cache;

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
        $file = $this->directory . '/' . sha1($key) . '.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
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
    }

    /** @param array<string, mixed> $value */
    public function set(string $key, array $value, int $ttlSeconds): void
    {
        $file = $this->directory . '/' . sha1($key) . '.json';
        $payload = [
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];

        $written = file_put_contents($file, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            throw new MiitException('failed to write cache file');
        }
    }
}
