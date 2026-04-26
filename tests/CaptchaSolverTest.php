<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Captcha\CaptchaSolver;
use Miit\Captcha\Rect;
use ReflectionClass;
use ReflectionMethod;

final class CaptchaSolverTest
{
    public static function run(): void
    {
        self::candidateOffsetsRejectSuspiciousLeft();
        self::candidateOffsetsKeepTemplatePriorityAndDeduplicate();
    }

    private static function candidateOffsetsRejectSuspiciousLeft(): void
    {
        $solver = self::solverWithoutConstructor();
        $offsets = self::invoke($solver, 'candidateOffsets', [5, 8]);

        if ($offsets !== []) {
            throw new \RuntimeException('captcha offsets should reject suspicious left edge values');
        }
    }

    private static function candidateOffsetsKeepTemplatePriorityAndDeduplicate(): void
    {
        $solver = self::solverWithoutConstructor();
        $offsets = self::invoke($solver, 'candidateOffsetsForDetections', [[
            ['method' => 'template', 'rect' => new Rect(142, 5, 213, 76, 5000)],
            ['method' => 'image', 'rect' => new Rect(144, 5, 215, 76, 5000)],
        ], 2]);

        $expected = [142, 141, 143, 140, 144, 145, 146];
        if ($offsets !== $expected) {
            throw new \RuntimeException('captcha offsets should preserve detection priority and remove duplicates');
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
