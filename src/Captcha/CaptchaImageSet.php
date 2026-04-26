<?php

declare(strict_types=1);

namespace Miit\Captcha;

use GdImage;

final class CaptchaImageSet
{
    public function __construct(
        public readonly GdImage $big,
        public readonly ?GdImage $small,
        public readonly int $bigWidth,
        public readonly int $bigHeight,
        public readonly int $smallWidth,
        public readonly int $smallHeight
    ) {
    }
}
