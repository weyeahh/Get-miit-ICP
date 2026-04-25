<?php

declare(strict_types=1);

namespace Miit\Config;

final class AppConfig
{
    private const DEFAULTS = [
        'cache.schema_version' => 'v1',
        'cache.success_ttl' => 86400,
        'cache.miss_ttl' => 1800,
        'ratelimit.global_qps' => 3,
        'ratelimit.ip_per_minute' => 20,
        'ratelimit.domain_per_window' => 3,
        'ratelimit.domain_window_seconds' => 300,
        'ratelimit.domain_cooldown_seconds' => 120,
        'ratelimit.global_cooldown_seconds' => 15,
        'ratelimit.domain_wait_timeout_seconds' => 16,
        'ratelimit.domain_wait_interval_milliseconds' => 250,
        'debug.allow_query_toggle' => false,
        'log.max_detail_length' => 512,
    ];

    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->values = self::DEFAULTS;
        foreach ($overrides as $key => $value) {
            $this->values[$key] = $value;
        }
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? 0);
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }
}
