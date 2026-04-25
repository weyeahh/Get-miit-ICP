<?php

declare(strict_types=1);

namespace Miit\Cache;

use Miit\Config\AppConfig;

final class QueryCache
{
    public function __construct(private readonly FileCache $cache, private readonly AppConfig $config)
    {
    }

    /** @return array<string, mixed>|null */
    public function getSuccess(string $domain): ?array
    {
        $payload = $this->cache->get($this->key('success', $domain));
        if (!is_array($payload)) {
            return null;
        }

        if (($payload['_schema_version'] ?? '') !== $this->config->string('cache.schema_version')) {
            return null;
        }

        $detail = $payload['detail'] ?? null;
        return is_array($detail) ? $detail : null;
    }

    /** @param array<string, mixed> $detail */
    public function putSuccess(string $domain, array $detail): void
    {
        $this->cache->set($this->key('success', $domain), [
            '_schema_version' => $this->config->string('cache.schema_version'),
            'detail' => $detail,
        ], $this->config->int('cache.success_ttl'));
    }

    /** @return array<string, mixed>|null */
    public function getMiss(string $domain): ?array
    {
        $payload = $this->cache->get($this->key('miss', $domain));
        if (!is_array($payload)) {
            return null;
        }

        return ($payload['_schema_version'] ?? '') === $this->config->string('cache.schema_version') ? $payload : null;
    }

    public function putMiss(string $domain): void
    {
        $this->cache->set($this->key('miss', $domain), [
            '_schema_version' => $this->config->string('cache.schema_version'),
            'domain' => $domain,
            'cached' => true,
        ], $this->config->int('cache.miss_ttl'));
    }

    private function key(string $prefix, string $domain): string
    {
        return $prefix . ':' . $this->config->string('cache.schema_version') . ':' . $domain;
    }
}
