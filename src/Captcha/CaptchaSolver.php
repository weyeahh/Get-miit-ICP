<?php

declare(strict_types=1);

namespace Miit\Captcha;

use GdImage;
use Miit\Api\CaptchaApi;
use Miit\Api\MiitClient;
use Miit\Exception\MiitException;
use Miit\Exception\UpstreamException;
use Miit\Support\Debug;

final class CaptchaSolver
{
    private const COLOR_TOLERANCE = 12;
    private const RELAXED_COLOR_TOLERANCE = 24;
    private const MAX_OFFSET_RADIUS = 4;
    private const MIN_VALID_LEFT = 5;
    private const MIN_COMPONENT_AREA = 900;
    private const MIN_SIDE_LENGTH = 24;
    private const TOP_HINT_ALLOWANCE = 8;
    private const GAP_APPROX_SIZE = 72;
    private const TARGET_R = 199;
    private const TARGET_G = 186;
    private const TARGET_B = 183;

    public function __construct(
        private readonly MiitClient $client,
        private readonly CaptchaApi $captchaApi
    ) {
    }

    /** @return array{rect: Rect, response: array<string, mixed>} */
    public function solve(string $captchaUuid, string $bigImage, int $topHint, bool $debug): array
    {
        $box = $this->detectSquareBase64WithHint($bigImage, $topHint);
        if ($box->left < self::MIN_VALID_LEFT) {
            $box = $this->estimateGapFromBinaryBase64WithHint($bigImage, $topHint);
        }

        Debug::log($debug, sprintf(
            'step=detect left=%d top=%d right=%d bottom=%d',
            $box->left,
            $box->top,
            $box->right,
            $box->bottom
        ));

        foreach ($this->candidateOffsets($box->left, self::MAX_OFFSET_RADIUS) as $left) {
            Debug::log($debug, 'step=checkImage attempt_left=' . $left);
            $response = $this->captchaApi->tryCheckImage($captchaUuid, $left);
            if (($response['code'] ?? 0) === 200 && ($response['success'] ?? false) === true) {
                $box->right += $left - $box->left;
                $box->left = $left;
                Debug::log($debug, 'step=checkImage success=true sign_len=' . strlen((string) ($response['params'] ?? '')));

                return ['rect' => $box, 'response' => $response];
            }
        }

        throw new UpstreamException('checkImage failed around detected left=' . $box->left, 'upstream query failed');
    }

    private function detectSquareBase64WithHint(string $encoded, int $topHint): Rect
    {
        $imageData = $this->decodeBase64Image($encoded);
        return $this->detectSquareFromBinaryWithHint($imageData, $topHint);
    }

    private function estimateGapFromBinaryBase64WithHint(string $encoded, int $topHint): Rect
    {
        $imageData = $this->decodeBase64Image($encoded);
        $img = imagecreatefromstring($imageData);
        if (!$img instanceof GdImage) {
            throw new UpstreamException('decode image failed', 'upstream query failed');
        }

        return $this->estimateGapFromHint($img, $topHint);
    }

    private function decodeBase64Image(string $encoded): string
    {
        $cleaned = trim($encoded);
        $commaPos = strpos($cleaned, ',');
        if ($commaPos !== false) {
            $cleaned = substr($cleaned, $commaPos + 1);
        }

        $data = base64_decode($cleaned, true);
        if ($data !== false) {
            return $data;
        }

        $cleaned = strtr($cleaned, '-_', '+/');
        $padding = strlen($cleaned) % 4;
        if ($padding > 0) {
            $cleaned .= str_repeat('=', 4 - $padding);
        }

        $data = base64_decode($cleaned, true);
        if ($data === false) {
            throw new UpstreamException('unsupported base64 image data', 'upstream query failed');
        }

        return $data;
    }

    private function detectSquareFromBinaryWithHint(string $binary, int $topHint): Rect
    {
        $img = imagecreatefromstring($binary);
        if (!$img instanceof GdImage) {
            throw new UpstreamException('decode image failed', 'upstream query failed');
        }

        foreach ([self::COLOR_TOLERANCE, self::RELAXED_COLOR_TOLERANCE] as $tolerance) {
            $box = $this->findCaptchaSquare($img, $tolerance, $topHint);
            if ($box !== null) {
                return $box;
            }
        }

        $box = $topHint >= 0 ? $this->estimateGapFromHint($img, $topHint) : null;

        if ($box !== null) {
            return $box;
        }

        throw new UpstreamException('square not found', 'upstream query failed');
    }

    private function findCaptchaSquare(GdImage $img, int $tolerance, int $topHint): ?Rect
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $visited = array_fill(0, $width * $height, false);
        $best = null;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = $y * $width + $x;
                if ($visited[$index]) {
                    continue;
                }
                $visited[$index] = true;

                if (!$this->isNearTarget($img, $x, $y, $tolerance)) {
                    continue;
                }

                $component = $this->floodFill($img, $x, $y, $tolerance, $visited, $width, $height);
                if (!$this->looksLikeSquare($component) || !$this->matchesTopHint($component, $topHint)) {
                    continue;
                }

                if ($best === null || $component->area > $best->area) {
                    $best = $component;
                }
            }
        }

        return $best;
    }

    /** @param array<int, bool> $visited */
    private function floodFill(GdImage $img, int $startX, int $startY, int $tolerance, array &$visited, int $width, int $height): Rect
    {
        $queue = [[$startX, $startY]];
        $head = 0;
        $component = new Rect($startX, $startY, $startX, $startY, 0);

        while ($head < count($queue)) {
            [$x, $y] = $queue[$head++];
            $component->area++;
            $component->left = min($component->left, $x);
            $component->top = min($component->top, $y);
            $component->right = max($component->right, $x);
            $component->bottom = max($component->bottom, $y);

            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $nx >= $width || $ny < 0 || $ny >= $height) {
                    continue;
                }

                $index = $ny * $width + $nx;
                if ($visited[$index]) {
                    continue;
                }
                $visited[$index] = true;

                if (!$this->isNearTarget($img, $nx, $ny, $tolerance)) {
                    continue;
                }

                $queue[] = [$nx, $ny];
            }
        }

        return $component;
    }

    private function estimateGapFromHint(GdImage $img, int $topHint): Rect
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $top = $this->clamp($topHint, 0, max(0, $height - self::GAP_APPROX_SIZE));
        $bestLeft = 0;
        $bestScore = -1;

        for ($left = 0; $left <= max(0, $width - self::GAP_APPROX_SIZE); $left++) {
            $score = $this->windowScore($img, $left, $top, self::GAP_APPROX_SIZE, $width, $height);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLeft = $left;
            }
        }

        return new Rect(
            $bestLeft,
            $top,
            min($width - 1, $bestLeft + self::GAP_APPROX_SIZE - 1),
            min($height - 1, $top + self::GAP_APPROX_SIZE - 1),
            self::GAP_APPROX_SIZE * self::GAP_APPROX_SIZE
        );
    }

    private function windowScore(GdImage $img, int $left, int $top, int $size, int $width, int $height): int
    {
        $score = 0;
        for ($y = $top; $y < min($height, $top + $size); $y++) {
            for ($x = $left; $x < min($width, $left + $size); $x++) {
                $score += $this->gapPixelScore($this->rgbAt($img, $x, $y));
            }
        }

        return $score;
    }

    /** @param array{r:int,g:int,b:int} $rgb */
    private function gapPixelScore(array $rgb): int
    {
        $maxChannel = max($rgb['r'], $rgb['g'], $rgb['b']);
        $minChannel = min($rgb['r'], $rgb['g'], $rgb['b']);
        $spread = $maxChannel - $minChannel;
        $avg = intdiv($rgb['r'] + $rgb['g'] + $rgb['b'], 3);

        if ($avg < 100 || $avg > 235) {
            return 0;
        }

        $score = max(0, 45 - $spread * 2);
        $score += max(0, 30 - abs($avg - 189));

        if ($this->channelDistance($rgb['r'], self::TARGET_R) <= self::RELAXED_COLOR_TOLERANCE
            && $this->channelDistance($rgb['g'], self::TARGET_G) <= self::RELAXED_COLOR_TOLERANCE
            && $this->channelDistance($rgb['b'], self::TARGET_B) <= self::RELAXED_COLOR_TOLERANCE) {
            $score += 25;
        }

        return $score;
    }

    private function looksLikeSquare(Rect $box): bool
    {
        $width = $box->right - $box->left + 1;
        $height = $box->bottom - $box->top + 1;
        if ($box->area < self::MIN_COMPONENT_AREA || $width < self::MIN_SIDE_LENGTH || $height < self::MIN_SIDE_LENGTH) {
            return false;
        }

        return $width > $height ? ($width - $height) <= intdiv($width, 3) : ($height - $width) <= intdiv($height, 3);
    }

    private function matchesTopHint(Rect $box, int $topHint): bool
    {
        if ($topHint < 0) {
            return true;
        }

        return abs($box->top - $topHint) <= self::TOP_HINT_ALLOWANCE;
    }

    private function isNearTarget(GdImage $img, int $x, int $y, int $tolerance): bool
    {
        $rgb = $this->rgbAt($img, $x, $y);

        return $this->channelDistance($rgb['r'], self::TARGET_R) <= $tolerance
            && $this->channelDistance($rgb['g'], self::TARGET_G) <= $tolerance
            && $this->channelDistance($rgb['b'], self::TARGET_B) <= $tolerance;
    }

    /** @return array{r:int,g:int,b:int} */
    private function rgbAt(GdImage $img, int $x, int $y): array
    {
        $color = imagecolorat($img, $x, $y);

        return [
            'r' => ($color >> 16) & 0xFF,
            'g' => ($color >> 8) & 0xFF,
            'b' => $color & 0xFF,
        ];
    }

    private function channelDistance(int $a, int $b): int
    {
        return abs($a - $b);
    }

    private function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }

    /** @return list<int> */
    private function candidateOffsets(int $center, int $radius): array
    {
        $offsets = [];
        $seen = [];

        $add = static function (int $value) use (&$offsets, &$seen): void {
            if ($value < self::MIN_VALID_LEFT || isset($seen[$value])) {
                return;
            }

            $seen[$value] = true;
            $offsets[] = $value;
        };

        $add($center);
        for ($delta = 1; $delta <= $radius; $delta++) {
            $add($center - $delta);
            $add($center + $delta);
        }

        return $offsets;
    }
}
