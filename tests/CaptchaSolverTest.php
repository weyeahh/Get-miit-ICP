<?php

declare(strict_types=1);

namespace Miit\Tests;

use Miit\Captcha\CaptchaSolver;
use Miit\Captcha\DetectionCandidate;
use Miit\Captcha\Rect;
use ReflectionClass;
use ReflectionMethod;

final class CaptchaSolverTest
{
    public static function run(): void
    {
        self::candidateOffsetsExpandAroundCenterLikeGoImplementation();
        self::candidateOffsetsDeduplicateAndKeepNonNegativeValues();
        self::rankCandidatesPrefersTemplateCandidatesOverFallbacks();
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

    private static function rankCandidatesPrefersTemplateCandidatesOverFallbacks(): void
    {
        $solver = self::solverWithoutConstructor();
        $candidates = self::invoke($solver, 'rankCandidates', [[
            new DetectionCandidate('estimate', new Rect(428, 10, 499, 81, 5184), 0.32),
            new DetectionCandidate('image', new Rect(0, 28, 71, 99, 5184), 0.82),
            new DetectionCandidate('template-content', new Rect(172, 28, 243, 99, 5184), 0.74),
            new DetectionCandidate('template-contrast', new Rect(180, 28, 251, 99, 5184), 0.78),
        ]]);

        if (!$candidates[0] instanceof DetectionCandidate || $candidates[0]->method !== 'template-contrast') {
            throw new \RuntimeException('captcha candidate ranking should prefer template candidates over image and estimate fallbacks');
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
