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
        self::rankCandidatesPrefersTemplateMaskOverOtherMethods();
        self::rankCandidatesPrefersHigherConfidenceWithinSameMethod();
        self::deduplicateCandidatesRemovesExactMethodPositionDuplicates();
    }

    private static function rankCandidatesPrefersTemplateMaskOverOtherMethods(): void
    {
        $solver = self::solverWithoutConstructor();
        $ranked = self::invoke($solver, 'rankCandidates', [[
            new DetectionCandidate('estimate', new Rect(80, 5, 151, 76, 5000), 0.05),
            new DetectionCandidate('image', new Rect(144, 5, 215, 76, 5000), 0.90),
            new DetectionCandidate('template-content', new Rect(143, 5, 214, 76, 5000), 0.75),
            new DetectionCandidate('template-mask', new Rect(142, 5, 213, 76, 5000), 0.50),
        ]]);

        if (!$ranked[0] instanceof DetectionCandidate || $ranked[0]->method !== 'template-mask') {
            throw new \RuntimeException('captcha ranking should prefer template-mask candidates over lower-priority methods');
        }
    }

    private static function rankCandidatesPrefersHigherConfidenceWithinSameMethod(): void
    {
        $solver = self::solverWithoutConstructor();
        $ranked = self::invoke($solver, 'rankCandidates', [[
            new DetectionCandidate('template-content', new Rect(141, 5, 212, 76, 5000), 0.41),
            new DetectionCandidate('template-content', new Rect(142, 5, 213, 76, 5000), 0.77),
        ]]);

        if (!$ranked[0] instanceof DetectionCandidate || $ranked[0]->rect->left !== 142) {
            throw new \RuntimeException('captcha ranking should prefer higher-confidence candidates within the same method');
        }
    }

    private static function deduplicateCandidatesRemovesExactMethodPositionDuplicates(): void
    {
        $solver = self::solverWithoutConstructor();
        $unique = self::invoke($solver, 'deduplicateCandidates', [[
            new DetectionCandidate('template-mask', new Rect(142, 5, 213, 76, 5000), 0.60),
            new DetectionCandidate('template-mask', new Rect(142, 5, 213, 76, 5000), 0.90),
            new DetectionCandidate('image', new Rect(142, 5, 213, 76, 5000), 0.80),
        ]]);

        if (count($unique) !== 2) {
            throw new \RuntimeException('captcha deduplication should only collapse exact method-position duplicates');
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
