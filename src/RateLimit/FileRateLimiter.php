<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Exception\StorageException;
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
        $this->gc();
        $file = $this->directory . '/' . sha1($key) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new StorageException('failed to open rate limit file', 'service storage is not ready');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException('failed to lock rate limit file', 'service storage is not ready');
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
                throw new StorageException('failed to encode rate limit state', 'service storage is not ready');
            }

            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new StorageException('failed to truncate rate limit file', 'service storage is not ready');
            }

            $written = fwrite($handle, $json);
            if ($written === false || $written !== strlen($json)) {
                throw new StorageException('failed to write complete rate limit state', 'service storage is not ready');
            }

            if (!fflush($handle)) {
                throw new StorageException('failed to flush rate limit state', 'service storage is not ready');
            }

            return $state['count'] <= $limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function setCooldown(string $key, int $ttlSeconds): void
    {
        $this->gc();
        $file = $this->directory . '/' . sha1('cooldown:' . $key) . '.json';
        $json = json_encode([
            'cooldown_until' => time() + max(1, $ttlSeconds),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new StorageException('failed to encode cooldown state', 'service storage is not ready');
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new StorageException('failed to open cooldown file', 'service storage is not ready');
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException('failed to lock cooldown file', 'service storage is not ready');
        }

        try {
            rewind($handle);
            if (!ftruncate($handle, 0)) {
                throw new StorageException('failed to truncate cooldown file', 'service storage is not ready');
            }

            $written = fwrite($handle, $json);
            if ($written === false || $written !== strlen($json)) {
                throw new StorageException('failed to write complete cooldown state', 'service storage is not ready');
            }

            if (!fflush($handle)) {
                throw new StorageException('failed to flush cooldown state', 'service storage is not ready');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function inCooldown(string $key): bool
    {
        $state = $this->read($this->directory . '/' . sha1('cooldown:' . $key) . '.json', true);
        return (int) ($state['cooldown_until'] ?? 0) > time();
    }

    /** @param array<int, array{key:string,window:int,limit:int}> $rules */
    public function consumeAll(array $rules): void
    {
        $this->gc();
        $handles = [];
        foreach ($rules as $rule) {
            $file = $this->directory . '/' . sha1($rule['key']) . '.json';
            $handles[] = [
                'rule' => $rule,
                'file' => $file,
            ];
        }

        usort($handles, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        foreach ($handles as $index => $item) {
            $handle = fopen($item['file'], 'c+');
            if ($handle === false) {
                throw new StorageException('failed to open rate limit file', 'service storage is not ready');
            }
            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                throw new StorageException('failed to lock rate limit file', 'service storage is not ready');
            }
            $handles[$index]['handle'] = $handle;
        }

        try {
            $states = [];
            $now = time();
            foreach ($handles as $index => $item) {
                $raw = stream_get_contents($item['handle']);
                $state = [];
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    $state = is_array($decoded) ? $decoded : [];
                }

                if (($state['window_started_at'] ?? 0) + $item['rule']['window'] <= $now) {
                    $state = ['window_started_at' => $now, 'count' => 0];
                }

                $nextCount = (int) ($state['count'] ?? 0) + 1;
                if ($nextCount > $item['rule']['limit']) {
                    throw new StorageException('rate limit exceeded', 'rate limit exceeded');
                }

                $state['count'] = $nextCount;
                $states[$index] = $state;
            }

            foreach ($handles as $index => $item) {
                $json = json_encode($states[$index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($json)) {
                    throw new StorageException('failed to encode rate limit state', 'service storage is not ready');
                }

                rewind($item['handle']);
                if (!ftruncate($item['handle'], 0)) {
                    throw new StorageException('failed to truncate rate limit file', 'service storage is not ready');
                }
                $written = fwrite($item['handle'], $json);
                if ($written === false || $written !== strlen($json)) {
                    throw new StorageException('failed to write complete rate limit state', 'service storage is not ready');
                }
                if (!fflush($item['handle'])) {
                    throw new StorageException('failed to flush rate limit state', 'service storage is not ready');
                }
            }
        } finally {
            foreach ($handles as $item) {
                if (isset($item['handle']) && is_resource($item['handle'])) {
                    flock($item['handle'], LOCK_UN);
                    fclose($item['handle']);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function read(string $file, bool $sharedLock = false): array
    {
        if (!is_file($file)) {
            return [];
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new StorageException('failed to open rate limit file for read', 'service storage is not ready');
        }

        if ($sharedLock && !flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new StorageException('failed to lock rate limit file for read', 'service storage is not ready');
        }

        try {
            $raw = stream_get_contents($handle);
            if (!is_string($raw) || $raw === '') {
                return [];
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        } finally {
            if ($sharedLock) {
                flock($handle, LOCK_UN);
            }
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

            $cooldownUntil = (int) ($decoded['cooldown_until'] ?? 0);
            if ($cooldownUntil > 0 && $cooldownUntil < $now) {
                @unlink($file);
                continue;
            }

            $windowStart = (int) ($decoded['window_started_at'] ?? 0);
            if ($windowStart > 0 && ($now - $windowStart) > 3600) {
                @unlink($file);
            }
        }
    }
}
