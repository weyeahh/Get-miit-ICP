<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

Miit\Tests\DomainNormalizerTest::run();
Miit\Tests\EnvironmentGuardTest::run();
Miit\Tests\JsonResponseTest::run();
Miit\Tests\QueryCacheVersionTest::run();
Miit\Tests\ResponseFormatterTest::run();
Miit\Tests\AppConfigTest::run();
Miit\Tests\RecordNotFoundExceptionTest::run();

fwrite(STDOUT, "tests passed\n");
