<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Exception\InternalErrorException;
use Miit\Support\ResponseFormatter;

final class ResponseFormatterTest
{
    public static function run(): void
    {
        $thrown = false;
        try {
            ResponseFormatter::successPayload([
                'domain' => 'example.com',
                'unitName' => 'Example',
            ]);
        } catch (InternalErrorException) {
            $thrown = true;
        }

        if (!$thrown) {
            throw new \RuntimeException('response formatter should reject missing fields');
        }
    }
}
