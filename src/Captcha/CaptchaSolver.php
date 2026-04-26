<?php

declare(strict_types=1);

namespace Miit\Captcha;

use GdImage;
use Miit\Api\CaptchaApi;
use Miit\Api\MiitClient;
use Miit\Config\AppConfig;
use Miit\Exception\UpstreamException;
use Miit\Support\AppPaths;
use Miit\Support\Debug;

final class CaptchaSolver
{
    private const COLOR_TOLERANCE = 12;
    private const RELAXED_COLOR_TOLERANCE = 24;
    private const MIN_VALID_LEFT = 5;
    private const MAX_CHALLENGE_ATTEMPTS = 5;
    private const DEFAULT_LEFT = 80;
    private const TEMPLATE_TOP_MARGIN = 8;
    private const TEMPLATE_MIN_MASK_SCORE = 18;
    private const TEMPLATE_MAX_CONTENT_DIFF = 60;
    private const TEMPLATE_SAMPLE_STEP = 3;
    private const TEMPLATE_ALPHA_THRESHOLD = 16;
    private const MIN_COMPONENT_AREA = 900;
    private const MIN_SIDE_LENGTH = 24;
    private const TOP_HINT_ALLOWANCE = 8;
    private const GAP_APPROX_SIZE = 72;
    private const TARGET_R = 199;
    private const TARGET_G = 186;
    private const TARGET_B = 183;
    private const MAX_LOGGED_CANDIDATES = 6;

    private AppConfig $config;

    public function __construct(
        private readonly MiitClient $client,
        private readonly CaptchaApi $captchaApi
    ) {
        $this->config = new AppConfig();
    }

    /** @return array{rect: Rect, response: array<string, mixed>} */
    public function solve(string $captchaUuid, string $bigImage, string $smallImage, int $topHint, bool $debug): array
    {
        $challenge = new CaptchaChallenge($captchaUuid, $bigImage, $smallImage, $topHint);
        $failures = [];

        for ($attempt = 1; $attempt <= self::MAX_CHALLENGE_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $challenge = $this->requestChallenge($debug, $attempt);
            }

            $result = $this->trySolveChallenge($challenge, $attempt, $debug);
            if (isset($result['response'])) {
                return $result;
            }

            $failures[] = $result['failure'];
        }

        throw new UpstreamException('checkImage failed after fresh challenge attempts=' . $this->formatFailures($failures), 'upstream query failed');
    }

    /**
     * @return array{rect: Rect, response: array<string, mixed>}|array{failure: array<string, mixed>}
     */
    private function trySolveChallenge(CaptchaChallenge $challenge, int $challengeAttempt, bool $debug): array
    {
        $candidates = $this->detectCandidates($challenge, $debug);
        if ($candidates === []) {
            throw new UpstreamException('captcha candidates are empty', 'upstream query failed');
        }

        $selected = $candidates[0];
        $left = $selected->rect->left;

        Debug::log($debug, sprintf(
            'step=detect method=%s left=%d top=%d right=%d bottom=%d',
            $selected->method,
            $selected->rect->left,
            $selected->rect->top,
            $selected->rect->right,
            $selected->rect->bottom
        ), [
            'challenge_attempt' => $challengeAttempt,
            'selected_confidence' => $selected->confidence,
            'candidates' => $this->candidateSummaries($candidates),
        ]);

        $this->persistChallengeSamples($challenge, $challengeAttempt, $candidates, $selected, $debug);

        Debug::log($debug, 'step=checkImage attempt_left=' . $left, [
            'challenge_attempt' => $challengeAttempt,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
        ]);

        $response = $this->captchaApi->tryCheckImage($challenge->uuid, $left);
        if (($response['code'] ?? 0) === 200 && ($response['success'] ?? false) === true) {
            $rect = new Rect(
                $left,
                $selected->rect->top,
                $selected->rect->right + ($left - $selected->rect->left),
                $selected->rect->bottom,
                $selected->rect->area
            );

            Debug::log($debug, 'step=checkImage success=true sign_len=' . strlen((string) ($response['params'] ?? '')), [
                'challenge_attempt' => $challengeAttempt,
                'method' => $selected->method,
                'selected_left' => $left,
            ]);

            return ['rect' => $rect, 'response' => $response];
        }

        $failure = [
            'challenge_attempt' => $challengeAttempt,
            'attempt_left' => $left,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
            'code' => $response['code'] ?? null,
            'success' => $response['success'] ?? null,
            'msg' => $response['msg'] ?? null,
        ];

        Debug::log($debug, 'step=checkImage rejected', $failure);

        return ['failure' => $failure];
    }

    /** @return list<DetectionCandidate> */
    private function detectCandidates(CaptchaChallenge $challenge, bool $debug): array
    {
        $primary = [];

        foreach ($this->matchTemplateCandidates($challenge->bigImage, $challenge->smallImage, $challenge->height) as $candidate) {
            $primary[] = $candidate;
        }

        $image = $this->detectSquareBase64WithHint($challenge->bigImage, $challenge->height);
        if ($image !== null) {
            if ($this->isSuspiciousLeft($image->left)) {
                Debug::log($debug, 'step=detect rejected_suspicious_left', [
                    'method' => 'image',
                    'left' => $image->left,
                    'min_valid_left' => self::MIN_VALID_LEFT,
                ]);
            } else {
                $primary[] = new DetectionCandidate('image', $image, $this->imageConfidence($image), [
                    'area' => $image->area,
                ]);
            }
        }

        $primary = $this->deduplicateCandidates($primary);
        $rankedPrimary = $this->rankCandidates($primary);
        if ($rankedPrimary !== []) {
            return $rankedPrimary;
        }

        $estimate = $this->estimateGapFromBinaryBase64WithHint($challenge->bigImage, $challenge->height);
        return [new DetectionCandidate('estimate', $estimate, 0.05, [
            'reason' => 'no_primary_candidates',
        ])];
    }

    private function detectSquareBase64WithHint(string $encoded, int $topHint): ?Rect
    {
        $imageData = $this->decodeBase64Image($encoded);
        return $this->detectSquareFromBinaryWithHint($imageData, $topHint);
    }

    private function detectSquareFromBinaryWithHint(string $binary, int $topHint): ?Rect
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

        return null;
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

    /** @return list<DetectionCandidate> */
    private function matchTemplateCandidates(string $bigEncoded, string $smallEncoded, int $topHint): array
    {
        if ($smallEncoded === '') {
            return [];
        }

        $bigData = $this->decodeBase64Image($bigEncoded);
        $smallData = $this->decodeBase64Image($smallEncoded);
        $big = imagecreatefromstring($bigData);
        $small = imagecreatefromstring($smallData);
        if (!$big instanceof GdImage || !$small instanceof GdImage) {
            return [];
        }

        $bigWidth = imagesx($big);
        $bigHeight = imagesy($big);
        $smallWidth = imagesx($small);
        $smallHeight = imagesy($small);
        if ($smallWidth <= 0 || $smallHeight <= 0 || $smallWidth > $bigWidth || $smallHeight > $bigHeight) {
            return [];
        }

        $startTop = max(0, $topHint - self::TEMPLATE_TOP_MARGIN);
        $endTop = min($bigHeight - $smallHeight, $topHint + self::TEMPLATE_TOP_MARGIN);
        if ($endTop < $startTop) {
            $startTop = 0;
            $endTop = max(0, $bigHeight - $smallHeight);
        }

        $maxTemplateLeft = $bigWidth - $smallWidth;
        if ($maxTemplateLeft < self::MIN_VALID_LEFT) {
            return [];
        }

        $bestMaskScore = -1;
        $bestMaskLeft = -1;
        $bestMaskTop = $startTop;
        $bestContentScore = PHP_INT_MAX;
        $bestContentLeft = -1;
        $bestContentTop = $startTop;

        for ($top = $startTop; $top <= $endTop; $top++) {
            for ($left = self::MIN_VALID_LEFT; $left <= $maxTemplateLeft; $left++) {
                $maskScore = $this->templateMaskScore($big, $small, $left, $top, $smallWidth, $smallHeight);
                if ($maskScore > $bestMaskScore) {
                    $bestMaskScore = $maskScore;
                    $bestMaskLeft = $left;
                    $bestMaskTop = $top;
                }

                $contentScore = $this->templateContentDifference($big, $small, $left, $top, $smallWidth, $smallHeight);
                if ($contentScore < $bestContentScore) {
                    $bestContentScore = $contentScore;
                    $bestContentLeft = $left;
                    $bestContentTop = $top;
                }
            }
        }

        $candidates = [];
        if ($bestMaskLeft >= self::MIN_VALID_LEFT && $bestMaskScore >= self::TEMPLATE_MIN_MASK_SCORE) {
            $candidates[] = new DetectionCandidate(
                'template-mask',
                new Rect(
                    $bestMaskLeft,
                    $bestMaskTop,
                    $bestMaskLeft + $smallWidth - 1,
                    $bestMaskTop + $smallHeight - 1,
                    $smallWidth * $smallHeight
                ),
                $this->maskConfidence($bestMaskScore),
                ['mask_score' => $bestMaskScore]
            );
        }

        if ($bestContentLeft >= self::MIN_VALID_LEFT && $bestContentScore <= self::TEMPLATE_MAX_CONTENT_DIFF) {
            $candidates[] = new DetectionCandidate(
                'template-content',
                new Rect(
                    $bestContentLeft,
                    $bestContentTop,
                    $bestContentLeft + $smallWidth - 1,
                    $bestContentTop + $smallHeight - 1,
                    $smallWidth * $smallHeight
                ),
                $this->contentConfidence($bestContentScore),
                ['content_diff' => $bestContentScore]
            );
        }

        return $candidates;
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

    private function estimateGapFromHint(GdImage $img, int $topHint): Rect
    {
        $width = imagesx($img);
        $height = imagesy($img);
        $top = $this->clamp($topHint, 0, max(0, $height - self::GAP_APPROX_SIZE));
        $minLeft = self::MIN_VALID_LEFT;
        $maxLeft = max($minLeft, $width - self::GAP_APPROX_SIZE);
        $bestLeft = $minLeft;
        $bestScore = -1;

        for ($left = $minLeft; $left <= $maxLeft; $left++) {
            $score = $this->windowScore($img, $left, $top, self::GAP_APPROX_SIZE, $width, $height);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLeft = $left;
                continue;
            }

            if ($score === $bestScore && abs($left - self::DEFAULT_LEFT) > abs($bestLeft - self::DEFAULT_LEFT)) {
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

    private function templateMaskScore(GdImage $big, GdImage $small, int $offsetX, int $offsetY, int $width, int $height): int
    {
        $score = 0;
        $samples = 0;

        for ($y = 0; $y < $height; $y += self::TEMPLATE_SAMPLE_STEP) {
            for ($x = 0; $x < $width; $x += self::TEMPLATE_SAMPLE_STEP) {
                $smallColor = imagecolorat($small, $x, $y);
                $alpha = ($smallColor >> 24) & 0x7F;
                if ($alpha > self::TEMPLATE_ALPHA_THRESHOLD) {
                    continue;
                }

                $bigRgb = $this->rgbAt($big, $offsetX + $x, $offsetY + $y);
                $score += $this->gapPixelScore($bigRgb);
                $samples++;
            }
        }

        if ($samples === 0) {
            return -1;
        }

        return intdiv($score, $samples);
    }

    private function templateContentDifference(GdImage $big, GdImage $small, int $offsetX, int $offsetY, int $width, int $height): int
    {
        $score = 0;
        $samples = 0;

        for ($y = 0; $y < $height; $y += self::TEMPLATE_SAMPLE_STEP) {
            for ($x = 0; $x < $width; $x += self::TEMPLATE_SAMPLE_STEP) {
                $smallColor = imagecolorat($small, $x, $y);
                $alpha = ($smallColor >> 24) & 0x7F;
                if ($alpha > self::TEMPLATE_ALPHA_THRESHOLD) {
                    continue;
                }

                $smallRgb = [
                    'r' => ($smallColor >> 16) & 0xFF,
                    'g' => ($smallColor >> 8) & 0xFF,
                    'b' => $smallColor & 0xFF,
                ];
                $bigRgb = $this->rgbAt($big, $offsetX + $x, $offsetY + $y);

                $score += abs($smallRgb['r'] - $bigRgb['r']);
                $score += abs($smallRgb['g'] - $bigRgb['g']);
                $score += abs($smallRgb['b'] - $bigRgb['b']);
                $samples++;
            }
        }

        if ($samples === 0) {
            return PHP_INT_MAX;
        }

        return intdiv($score, $samples);
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

    private function maskConfidence(int $score): float
    {
        return min(0.99, max(0.0, $score / 40));
    }

    private function contentConfidence(int $score): float
    {
        return min(0.98, max(0.0, 1 - ($score / max(1, self::TEMPLATE_MAX_CONTENT_DIFF))));
    }

    private function imageConfidence(Rect $rect): float
    {
        $sideWidth = $rect->right - $rect->left + 1;
        $sideHeight = $rect->bottom - $rect->top + 1;
        $sizePenalty = abs(self::GAP_APPROX_SIZE - $sideWidth) + abs(self::GAP_APPROX_SIZE - $sideHeight);

        return max(0.4, 0.9 - ($sizePenalty / 200));
    }

    /** @param list<DetectionCandidate> $candidates
     *  @return list<DetectionCandidate>
     */
    private function rankCandidates(array $candidates): array
    {
        usort($candidates, function (DetectionCandidate $left, DetectionCandidate $right): int {
            $priorityDiff = $this->candidatePriority($left->method) <=> $this->candidatePriority($right->method);
            if ($priorityDiff !== 0) {
                return $priorityDiff;
            }

            $confidenceDiff = $right->confidence <=> $left->confidence;
            if ($confidenceDiff !== 0) {
                return $confidenceDiff;
            }

            return $left->rect->left <=> $right->rect->left;
        });

        return $candidates;
    }

    private function candidatePriority(string $method): int
    {
        return match ($method) {
            'template-mask' => 0,
            'template-content' => 1,
            'image' => 2,
            'estimate' => 3,
            default => 4,
        };
    }

    /** @param list<DetectionCandidate> $candidates
     *  @return list<DetectionCandidate>
     */
    private function deduplicateCandidates(array $candidates): array
    {
        $unique = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $key = $candidate->method . ':' . $candidate->rect->left . ':' . $candidate->rect->top;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /** @param list<DetectionCandidate> $candidates
     *  @return list<array<string, mixed>>
     */
    private function candidateSummaries(array $candidates): array
    {
        $summaries = [];
        foreach (array_slice($candidates, 0, self::MAX_LOGGED_CANDIDATES) as $candidate) {
            $summaries[] = [
                'method' => $candidate->method,
                'left' => $candidate->rect->left,
                'top' => $candidate->rect->top,
                'right' => $candidate->rect->right,
                'bottom' => $candidate->rect->bottom,
                'confidence' => $candidate->confidence,
                'diagnostics' => $candidate->diagnostics,
            ];
        }

        return $summaries;
    }

    private function requestChallenge(bool $debug, int $challengeAttempt): CaptchaChallenge
    {
        $clientUid = CaptchaApi::newClientUid();
        Debug::log($debug, 'step=getCheckImagePoint retry clientUid=' . $clientUid, [
            'challenge_attempt' => $challengeAttempt,
        ]);

        $challenge = $this->captchaApi->getCheckImagePoint($clientUid);
        $params = is_array($challenge['params'] ?? null) ? $challenge['params'] : [];
        $captchaUuid = (string) ($params['uuid'] ?? '');
        $bigImage = (string) ($params['bigImage'] ?? '');
        $smallImage = (string) ($params['smallImage'] ?? '');
        $height = (int) ($params['height'] ?? -1);
        if ($captchaUuid === '' || $bigImage === '' || $height < 0) {
            throw new UpstreamException('captcha retry challenge params missing', 'upstream query failed');
        }

        Debug::log($debug, 'step=getCheckImagePoint retry success=true captchaUUID=' . $captchaUuid . ' height=' . $height, [
            'challenge_attempt' => $challengeAttempt,
        ]);

        return new CaptchaChallenge($captchaUuid, $bigImage, $smallImage, $height, $clientUid);
    }

    /** @param list<array<string, mixed>> $failures */
    private function formatFailures(array $failures): string
    {
        $parts = [];
        foreach ($failures as $failure) {
            $parts[] = sprintf(
                '#%s:left=%s,method=%s,msg=%s',
                (string) ($failure['challenge_attempt'] ?? ''),
                (string) ($failure['attempt_left'] ?? ''),
                (string) ($failure['method'] ?? ''),
                (string) ($failure['msg'] ?? '')
            );
        }

        return implode(';', $parts);
    }

    private function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }

    private function isSuspiciousLeft(int $left): bool
    {
        return $left <= self::MIN_VALID_LEFT;
    }

    /** @param list<DetectionCandidate> $candidates */
    private function persistChallengeSamples(CaptchaChallenge $challenge, int $challengeAttempt, array $candidates, DetectionCandidate $selected, bool $debug): void
    {
        if (!$debug || !$this->config->bool('debug.store_captcha_samples')) {
            return;
        }

        $dirName = sprintf('%s-%02d-%s', date('Ymd-His'), $challengeAttempt, preg_replace('/[^a-zA-Z0-9_-]+/', '-', $challenge->uuid) ?: 'captcha');
        $dir = AppPaths::ensureDir(AppPaths::storagePath('debug/captcha/' . $dirName), true);

        $big = $this->decodeBase64Image($challenge->bigImage);
        $small = $challenge->smallImage === '' ? '' : $this->decodeBase64Image($challenge->smallImage);

        @file_put_contents($dir . '/big.png', $big);
        if ($small !== '') {
            @file_put_contents($dir . '/small.png', $small);
        }

        $metadata = json_encode([
            'uuid' => $challenge->uuid,
            'clientUid' => $challenge->clientUid,
            'height' => $challenge->height,
            'selected' => [
                'method' => $selected->method,
                'left' => $selected->rect->left,
                'top' => $selected->rect->top,
                'confidence' => $selected->confidence,
            ],
            'candidates' => $this->candidateSummaries($candidates),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($metadata)) {
            @file_put_contents($dir . '/metadata.json', $metadata);
        }
    }
}
