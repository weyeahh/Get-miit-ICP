<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Config\AppConfig;
use Miit\Exception\RateLimitException;

final class QueryGuard
{
    public function __construct(private readonly FileRateLimiter $limiter, private readonly AppConfig $config)
    {
    }

    public function assertAllowed(string $ip, string $domain): void
    {
        if ($this->limiter->inCooldown('domain:' . $domain)) {
            throw new RateLimitException('domain is temporarily cooling down');
        }

        if ($this->limiter->inCooldown('global')) {
            throw new RateLimitException('service is temporarily cooling down');
        }

        try {
            $this->limiter->consumeAll([
                ['key' => 'domain:' . $domain, 'window' => $this->config->int('ratelimit.domain_window_seconds'), 'limit' => $this->config->int('ratelimit.domain_per_window')],
                ['key' => 'global:qps', 'window' => 1, 'limit' => $this->config->int('ratelimit.global_qps')],
                ['key' => 'ip:' . $ip, 'window' => 60, 'limit' => $this->config->int('ratelimit.ip_per_minute')],
            ]);
        } catch (\Miit\Exception\StorageException $e) {
            if ($e->userMessage() === 'rate limit exceeded') {
                throw new RateLimitException('request rate exceeded', 'too many requests', previous: $e);
            }

            throw $e;
        }
    }

    public function markUpstreamFailure(string $domain): void
    {
        $this->limiter->setCooldown('domain:' . $domain, $this->config->int('ratelimit.domain_cooldown_seconds'));
        $this->limiter->setCooldown('global', $this->config->int('ratelimit.global_cooldown_seconds'));
    }
}
