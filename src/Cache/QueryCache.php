<?php

declare(strict_types=1);

namespace Miit\Cache;

final class QueryCache
{
    private const HIT_TTL = 86400;
    private const MISS_TTL = 1800;

    public function __construct(private readonly FileCache $cache)
    {
    }

    /** @return array<string, mixed>|null */
    public function getSuccess(string $domain): ?array
    {
        return $this->cache->get('success:' . $domain);
    }

    /** @param array<string, mixed> $detail */
    public function putSuccess(string $domain, array $detail): void
    {
        $this->cache->set('success:' . $domain, $detail, self::HIT_TTL);
    }

    /** @return array<string, mixed>|null */
    public function getMiss(string $domain): ?array
    {
        return $this->cache->get('miss:' . $domain);
    }

    public function putMiss(string $domain): void
    {
        $this->cache->set('miss:' . $domain, [
            'domain' => $domain,
            'cached' => true,
        ], self::MISS_TTL);
    }
}
