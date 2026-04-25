<?php

declare(strict_types=1);

namespace Miit\Captcha;

final class Rect
{
    public function __construct(
        public int $left,
        public int $top,
        public int $right,
        public int $bottom,
        public int $area
    ) {
    }
}
