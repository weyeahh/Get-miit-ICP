<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Exception\MiitException;
use Miit\Support\AppPaths;

final class FileRateLimiter
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = AppPaths::ensureDir($directory ?? AppPaths::storagePath('ratelimit'), true);
    }

    public function hit(string $key, int $windowSeconds, int $limit): bool
    {
        $file = $this->directory . '/' . sha1($key) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new MiitException('failed to open rate limit file');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new MiitException('failed to lock rate limit file');
        }

        try {
            $raw = stream_get_contents($handle);
            $state = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $state = is_array($decoded) ? $decoded : [];
            }

            $now = time();
            if (($state['window_started_at'] ?? 0) + $windowSeconds <= $now) {
                $state = ['window_started_at' => $now, 'count' => 0];
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new MiitException('failed to encode rate limit state');
            }

            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new MiitException('failed to truncate rate limit file');
            }

            $written = fwrite($handle, $json);
            if ($written === false || $written !== strlen($json)) {
                throw new MiitException('failed to write complete rate limit state');
            }

            if (!fflush($handle)) {
                throw new MiitException('failed to flush rate limit state');
            }

            return $state['count'] <= $limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function setCooldown(string $key, int $ttlSeconds): void
    {
        $file = $this->directory . '/' . sha1('cooldown:' . $key) . '.json';
        $written = file_put_contents($file, (string) json_encode([
            'cooldown_until' => time() + max(1, $ttlSeconds),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            throw new MiitException('failed to write cooldown state');
        }
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
