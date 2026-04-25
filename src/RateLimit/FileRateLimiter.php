<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Support\AppPaths;

final class FileRateLimiter
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = AppPaths::ensureDir($directory ?? AppPaths::storagePath('ratelimit'));
    }

    public function hit(string $key, int $windowSeconds, int $limit): bool
    {
        $file = $this->directory . '/' . sha1($key) . '.json';
        $state = $this->read($file);
        $now = time();

        if (($state['window_started_at'] ?? 0) + $windowSeconds <= $now) {
            $state = ['window_started_at' => $now, 'count' => 0];
        }

        $state['count'] = (int) ($state['count'] ?? 0) + 1;
        file_put_contents($file, (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $state['count'] <= $limit;
    }

    public function setCooldown(string $key, int $ttlSeconds): void
    {
        $file = $this->directory . '/' . sha1('cooldown:' . $key) . '.json';
        file_put_contents($file, (string) json_encode([
            'cooldown_until' => time() + max(1, $ttlSeconds),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function inCooldown(string $key): bool
    {
        $state = $this->read($this->directory . '/' . sha1('cooldown:' . $key) . '.json');
        return (int) ($state['cooldown_until'] ?? 0) > time();
    }

    /** @return array<string, mixed> */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
