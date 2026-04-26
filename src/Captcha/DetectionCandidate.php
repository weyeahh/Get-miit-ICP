<?php

declare(strict_types=1);

namespace Miit\Captcha;

final class DetectionCandidate
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(
        public readonly string $method,
        public readonly Rect $rect,
        public readonly float $confidence,
        public readonly array $diagnostics = []
    ) {
    }
}
