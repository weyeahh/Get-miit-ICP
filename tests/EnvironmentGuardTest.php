<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Exception\EnvironmentException;
use Miit\Support\EnvironmentGuard;

final class EnvironmentGuardTest
{
    public static function run(): void
    {
        if (!extension_loaded('json')) {
            throw new \RuntimeException('json extension is required for tests');
        }

        $thrown = false;
        try {
            EnvironmentGuard::assertRuntimeReady();
        } catch (EnvironmentException) {
            $thrown = true;
        }

        if (!extension_loaded('curl') || !extension_loaded('gd')) {
            if (!$thrown) {
                throw new \RuntimeException('environment guard should fail when required extensions are missing');
            }
            return;
        }

        if ($thrown) {
            throw new \RuntimeException('environment guard failed unexpectedly');
        }
    }
}
