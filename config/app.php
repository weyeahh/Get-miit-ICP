<?php

declare(strict_types=1);

return [
    'cache' => [
        'schema_version' => 'v1',
        'success_ttl' => 86400,
        'miss_ttl' => 1800,
    ],
    'ratelimit' => [
        'global_qps' => 3,
        'ip_per_minute' => 20,
        'domain_per_window' => 3,
        'domain_window_seconds' => 300,
        'domain_cooldown_seconds' => 120,
        'global_cooldown_seconds' => 15,
        'domain_wait_timeout_seconds' => 3,
        'domain_wait_interval_milliseconds' => 250,
    ],
    'debug' => [
        'allow_query_toggle' => false,
    ],
    'log' => [
        'max_detail_length' => 512,
    ],
];
