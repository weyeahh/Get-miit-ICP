<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Exception\RecordNotFoundException;

final class RecordNotFoundExceptionTest
{
    public static function run(): void
    {
        $cacheable = new RecordNotFoundException('a', true);
        if (!$cacheable->cacheable()) {
            throw new \RuntimeException('cacheable flag should be true');
        }

        $nonCacheable = new RecordNotFoundException('b', false);
        if ($nonCacheable->cacheable()) {
            throw new \RuntimeException('cacheable flag should be false');
        }
    }
}
