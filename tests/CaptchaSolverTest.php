<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Captcha\CaptchaSolver;
use ReflectionClass;
use ReflectionMethod;

final class CaptchaSolverTest
{
    public static function run(): void
    {
        self::candidateOffsetsExpandAroundCenterLikeGoImplementation();
        self::candidateOffsetsDeduplicateAndKeepNonNegativeValues();
    }

    private static function candidateOffsetsExpandAroundCenterLikeGoImplementation(): void
    {
        $solver = self::solverWithoutConstructor();
        $offsets = self::invoke($solver, 'candidateOffsets', [80, 4]);
        $expected = [80, 79, 81, 78, 82, 77, 83, 76, 84];

        if ($offsets !== $expected) {
            throw new \RuntimeException('captcha offsets should expand around center in Go-style alternating order');
        }
    }

    private static function candidateOffsetsDeduplicateAndKeepNonNegativeValues(): void
    {
        $solver = self::solverWithoutConstructor();
        $offsets = self::invoke($solver, 'candidateOffsets', [1, 3]);
        $expected = [1, 0, 2, 3, 4];

        if ($offsets !== $expected) {
            throw new \RuntimeException('captcha offsets should keep non-negative unique values only');
        }
    }

    private static function solverWithoutConstructor(): CaptchaSolver
    {
        $reflection = new ReflectionClass(CaptchaSolver::class);
        $solver = $reflection->newInstanceWithoutConstructor();
        if (!$solver instanceof CaptchaSolver) {
            throw new \RuntimeException('failed to create captcha solver test double');
        }

        return $solver;
    }

    /** @param list<mixed> $args */
    private static function invoke(CaptchaSolver $solver, string $method, array $args): mixed
    {
        $reflection = new ReflectionMethod($solver, $method);

        return $reflection->invokeArgs($solver, $args);
    }
}
