<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Exception\RateLimitException;

final class QueryGuard
{
    public function __construct(private readonly FileRateLimiter $limiter)
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

        if (!$this->limiter->hit('global:qps', 1, 3)) {
            throw new RateLimitException('global request limit exceeded');
        }

        if (!$this->limiter->hit('ip:' . $ip, 60, 20)) {
            throw new RateLimitException('ip request limit exceeded');
        }

        if (!$this->limiter->hit('domain:' . $domain, 300, 3)) {
            throw new RateLimitException('domain request limit exceeded');
        }
    }

    public function markUpstreamFailure(string $domain): void
    {
        $this->limiter->setCooldown('domain:' . $domain, 120);
        $this->limiter->setCooldown('global', 15);
    }
}
