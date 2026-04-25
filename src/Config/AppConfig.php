<?php

declare(strict_types=1);

namespace Miit\Config;

final class AppConfig
{
    private const CONFIG_FILE = __DIR__ . '/../../config/app.php';

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->values = $this->loadDefaults();
        foreach ($this->envOverrides() as $key => $value) {
            $this->values[$key] = $value;
        }
        foreach ($overrides as $key => $value) {
            $this->values[$key] = $value;
        }
    }

    public function int(string $key): int
    {
        $value = (int) ($this->values[$key] ?? 0);

        return match ($key) {
            'cache.success_ttl' => max(60, min($value, 604800)),
            'cache.miss_ttl' => max(30, min($value, 86400)),
            'ratelimit.global_qps' => max(1, min($value, 1000)),
            'ratelimit.ip_per_minute' => max(1, min($value, 10000)),
            'ratelimit.domain_per_window' => max(1, min($value, 1000)),
            'ratelimit.domain_window_seconds' => max(1, min($value, 86400)),
            'ratelimit.domain_cooldown_seconds' => max(1, min($value, 3600)),
            'ratelimit.global_cooldown_seconds' => max(1, min($value, 3600)),
            'ratelimit.domain_wait_timeout_seconds' => max(0, min($value, 10)),
            'ratelimit.domain_wait_interval_milliseconds' => max(10, min($value, 1000)),
            'log.max_detail_length' => max(64, min($value, 4096)),
            default => $value,
        };
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    /** @return array<string, mixed> */
    private function envOverrides(): array
    {
        $map = [
            'MIIT_CACHE_SCHEMA_VERSION' => 'cache.schema_version',
            'MIIT_CACHE_SUCCESS_TTL' => 'cache.success_ttl',
            'MIIT_CACHE_MISS_TTL' => 'cache.miss_ttl',
            'MIIT_RATE_LIMIT_GLOBAL_QPS' => 'ratelimit.global_qps',
            'MIIT_RATE_LIMIT_IP_PER_MINUTE' => 'ratelimit.ip_per_minute',
            'MIIT_RATE_LIMIT_DOMAIN_PER_WINDOW' => 'ratelimit.domain_per_window',
            'MIIT_RATE_LIMIT_DOMAIN_WINDOW_SECONDS' => 'ratelimit.domain_window_seconds',
            'MIIT_RATE_LIMIT_DOMAIN_COOLDOWN_SECONDS' => 'ratelimit.domain_cooldown_seconds',
            'MIIT_RATE_LIMIT_GLOBAL_COOLDOWN_SECONDS' => 'ratelimit.global_cooldown_seconds',
            'MIIT_RATE_LIMIT_DOMAIN_WAIT_TIMEOUT_SECONDS' => 'ratelimit.domain_wait_timeout_seconds',
            'MIIT_RATE_LIMIT_DOMAIN_WAIT_INTERVAL_MILLISECONDS' => 'ratelimit.domain_wait_interval_milliseconds',
            'MIIT_DEBUG_ENABLED' => 'debug.enabled',
            'MIIT_LOG_MAX_DETAIL_LENGTH' => 'log.max_detail_length',
        ];

        $overrides = [];
        foreach ($map as $env => $key) {
            $value = getenv($env);
            if ($value === false || $value === '') {
                continue;
            }

            $default = $this->values[$key] ?? null;
            if (is_bool($default)) {
                $overrides[$key] = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            } elseif (is_int($default)) {
                $overrides[$key] = (int) $value;
            } else {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    /** @return array<string, mixed> */
    private function loadDefaults(): array
    {
        $loaded = require self::CONFIG_FILE;
        if (!is_array($loaded)) {
            throw new \RuntimeException('config/app.php must return an array');
        }

        return $this->flatten($loaded);
    }

    /** @param array<string, mixed> $values
     *  @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                foreach ($this->flatten($value, $fullKey) as $nestedKey => $nestedValue) {
                    $result[$nestedKey] = $nestedValue;
                }
                continue;
            }

            $result[$fullKey] = $value;
        }

        return $result;
    }
}
