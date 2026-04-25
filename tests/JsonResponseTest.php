<?php

declare(strict_types=1);

namespace Miit\Tests;

final class JsonResponseTest
{
    public static function run(): void
    {
        $payload = ['code' => 200, 'message' => 'ok', 'data' => ['x' => 'y']];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new \RuntimeException('json encoding failed unexpectedly');
        }
    }
}
