<?php

declare(strict_types=1);

namespace Miit\Captcha;

final class CaptchaChallenge
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $bigImage,
        public readonly string $smallImage,
        public readonly int $height,
        public readonly string $clientUid = ''
    ) {
    }
}
