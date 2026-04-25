<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Miit\Tests\DomainNormalizerTest::run();

fwrite(STDOUT, "tests passed\n");
