<?php

declare(strict_types=1);

namespace Miit\RateLimit;

use Miit\Support\AppPaths;
use Miit\Support\FileMutex;

final class DomainQueryLock
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = AppPaths::ensureDir($directory ?? AppPaths::storagePath('locks'), true);
    }

    public function mutexFor(string $domain): FileMutex
    {
        return new FileMutex('domain-query:' . $domain, $this->directory);
    }
}
