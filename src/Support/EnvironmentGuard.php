<?php

declare(strict_types=1);

namespace Miit\Support;

use Miit\Exception\EnvironmentException;

final class EnvironmentGuard
{
    public static function assertRuntimeReady(): void
    {
        foreach (['curl', 'gd', 'json'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new EnvironmentException(
                    'required extension missing: ' . $extension,
                    'service environment is not ready'
                );
            }
        }
    }
}
