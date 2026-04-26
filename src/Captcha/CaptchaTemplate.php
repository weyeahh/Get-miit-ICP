<?php

declare(strict_types=1);

namespace Miit\Captcha;

final class CaptchaTemplate
{
    /**
     * @param list<array{x:int,y:int}> $opaquePoints
     * @param list<array{x:int,y:int}> $borderPoints
     * @param list<array{x:int,y:int}> $shellPoints
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $opaquePoints,
        public readonly array $borderPoints,
        public readonly array $shellPoints
    ) {
    }
}
