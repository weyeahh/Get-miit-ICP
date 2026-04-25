<?php

declare(strict_types=1);

namespace Miit\Tests;

final class EnvironmentGuardTest
{
    public static function run(): void
    {
        if (!extension_loaded('json')) {
            throw new \RuntimeException('json extension is required for tests');
        }
    }
}
