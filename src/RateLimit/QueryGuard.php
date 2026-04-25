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

        if (!$this->limiter->hit('global:qps', 1, $this->config->int('ratelimit.global_qps'))) {
            throw new RateLimitException('global request limit exceeded');
        }

        if (!$this->limiter->hit('ip:' . $ip, 60, $this->config->int('ratelimit.ip_per_minute'))) {
            throw new RateLimitException('ip request limit exceeded');
        }

        if (!$this->limiter->hit('domain:' . $domain, $this->config->int('ratelimit.domain_window_seconds'), $this->config->int('ratelimit.domain_per_window'))) {
            throw new RateLimitException('domain request limit exceeded');
        }
    }

    public function markUpstreamFailure(string $domain): void
    {
        $this->limiter->setCooldown('domain:' . $domain, $this->config->int('ratelimit.domain_cooldown_seconds'));
        $this->limiter->setCooldown('global', $this->config->int('ratelimit.global_cooldown_seconds'));
    }
}
