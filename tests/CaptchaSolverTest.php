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
        self::rankCandidatesPrefersTemplateGapOverFallbacks();
        self::rankCandidatesUsesMethodBiasBeforeRawConfidence();
        self::deduplicateCandidatesRemovesExactMethodPositionDuplicates();
        self::selectDistinctEntriesKeepsSeparatedPeaks();
    }

    private static function rankCandidatesPrefersTemplateGapOverFallbacks(): void
    {
        $solver = self::solverWithoutConstructor();
        $ranked = self::invoke($solver, 'rankCandidates', [[
            new DetectionCandidate('estimate', new Rect(428, 5, 499, 76, 5000), 0.12),
            new DetectionCandidate('image', new Rect(144, 5, 215, 76, 5000), 0.64),
            new DetectionCandidate('template-content', new Rect(143, 5, 214, 76, 5000), 0.64),
            new DetectionCandidate('template-gap', new Rect(142, 5, 213, 76, 5000), 0.64),
        ]]);

        if (!$ranked[0] instanceof DetectionCandidate || $ranked[0]->method !== 'template-gap') {
            throw new \RuntimeException('captcha ranking should prefer template-gap candidates when scores are close');
        }
    }

    private static function rankCandidatesUsesMethodBiasBeforeRawConfidence(): void
    {
        $solver = self::solverWithoutConstructor();
        $ranked = self::invoke($solver, 'rankCandidates', [[
            new DetectionCandidate('template-content', new Rect(141, 5, 212, 76, 5000), 0.62),
            new DetectionCandidate('image', new Rect(142, 5, 213, 76, 5000), 0.62),
        ]]);

        if (!$ranked[0] instanceof DetectionCandidate || $ranked[0]->method !== 'template-content') {
            throw new \RuntimeException('captcha ranking should apply method bias before falling back to left ordering');
        }
    }

    private static function deduplicateCandidatesRemovesExactMethodPositionDuplicates(): void
    {
        $solver = self::solverWithoutConstructor();
        $unique = self::invoke($solver, 'deduplicateCandidates', [[
            new DetectionCandidate('template-gap', new Rect(142, 5, 213, 76, 5000), 0.60),
            new DetectionCandidate('template-gap', new Rect(142, 5, 213, 76, 5000), 0.90),
            new DetectionCandidate('image', new Rect(142, 5, 213, 76, 5000), 0.80),
        ]]);

        if (count($unique) !== 2) {
            throw new \RuntimeException('captcha deduplication should only collapse exact method-position duplicates');
        }
    }

    private static function selectDistinctEntriesKeepsSeparatedPeaks(): void
    {
        $solver = self::solverWithoutConstructor();
        $entries = self::invoke($solver, 'selectDistinctEntries', [[
            ['left' => 140, 'top' => 10, 'score' => 10.0],
            ['left' => 142, 'top' => 11, 'score' => 9.9],
            ['left' => 188, 'top' => 10, 'score' => 9.7],
            ['left' => 230, 'top' => 9, 'score' => 9.6],
        ], 3, 8]);

        $lefts = array_map(static fn (array $entry): int => $entry['left'], $entries);
        if ($lefts !== [140, 188, 230]) {
            throw new \RuntimeException('captcha distinct entry selection should keep separated high-score peaks');
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
