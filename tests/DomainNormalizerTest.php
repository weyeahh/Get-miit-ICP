<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Validation\DomainNormalizer;

final class DomainNormalizerTest
{
    public static function run(): void
    {
        $normalizer = new DomainNormalizer();
        if ($normalizer->normalize('Example.COM.') !== 'example.com') {
            throw new \RuntimeException('normalize failed');
        }
    }
}
