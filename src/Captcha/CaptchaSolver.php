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
    private const MIN_COMPONENT_AREA = 900;
    private const MIN_SIDE_LENGTH = 24;
    private const TOP_HINT_ALLOWANCE = 8;
    private const GAP_APPROX_SIZE = 72;
    private const MAX_OFFSET_RADIUS = 12;
    private const MAX_CHALLENGE_ATTEMPTS = 5;
    private const TEMPLATE_TOP_MARGIN = 10;
    private const TEMPLATE_ALPHA_THRESHOLD = 16;
    private const TEMPLATE_SAMPLE_STEP = 3;
    private const MAX_LOGGED_CANDIDATES = 8;
    private const TARGET_R = 199;
    private const TARGET_G = 186;
    private const TARGET_B = 183;

    private AppConfig $config;

    public function __construct(
        private readonly MiitClient $client,
        private readonly CaptchaApi $captchaApi
    ) {
        $this->config = new AppConfig();
    }

    /** @return array{rect: Rect, response: array<string, mixed>, solvedUuid: string} */
    public function solve(string $captchaUuid, string $bigImage, string $smallImage, int $topHint, bool $debug): array
    {
        $challenge = new CaptchaChallenge($captchaUuid, $bigImage, $smallImage, $topHint);
        $failures = [];

        for ($attempt = 1; $attempt <= self::MAX_CHALLENGE_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $challenge = $this->requestChallenge($debug, $attempt);
            }

            $result = $this->trySolveChallenge($challenge, $debug, $attempt);
            if (isset($result['response'])) {
                return $result;
            }

            $failures[] = $result['failure'];
        }

        throw new UpstreamException('checkImage failed after fresh challenge attempts=' . $this->formatFailures($failures), 'upstream query failed');
    }

    /**
     * @return array{rect: Rect, response: array<string, mixed>, solvedUuid: string}|array{failure: array<string, mixed>}
     */
    private function trySolveChallenge(CaptchaChallenge $challenge, bool $debug, int $challengeAttempt): array
    {
        $candidates = $this->detectCandidates($challenge, $debug);
        if ($candidates === []) {
            throw new UpstreamException('captcha candidates are empty', 'upstream query failed');
        }

        $selectedIndex = min($challengeAttempt - 1, count($candidates) - 1);
        $selected = $candidates[$selectedIndex];
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
            'selected_candidate_index' => $selectedIndex,
            'selected_confidence' => $selected->confidence,
            'candidates' => $this->candidateSummaries($candidates),
        ]);

        $this->persistChallengeSamples($challenge, $challengeAttempt, $selected, $candidates, $debug);

        Debug::log($debug, 'step=checkImage attempt_left=' . $left, [
            'challenge_attempt' => $challengeAttempt,
            'selected_candidate_index' => $selectedIndex,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
        ]);

        $response = $this->captchaApi->tryCheckImage($challenge->uuid, $left);
        if (($response['code'] ?? 0) === 200 && ($response['success'] ?? false) === true) {
            Debug::log($debug, 'step=checkImage success=true sign_len=' . strlen((string) ($response['params'] ?? '')), [
                'challenge_attempt' => $challengeAttempt,
                'selected_candidate_index' => $selectedIndex,
                'selected_left' => $left,
                'solved_uuid' => $challenge->uuid,
            ]);

            return [
                'rect' => $selected->rect,
                'response' => $response,
                'solvedUuid' => $challenge->uuid,
            ];
        }

        Debug::log($debug, 'step=checkImage rejected', [
            'challenge_attempt' => $challengeAttempt,
            'attempt_left' => $left,
            'selected_candidate_index' => $selectedIndex,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
            'code' => $response['code'] ?? null,
            'success' => $response['success'] ?? null,
            'msg' => $response['msg'] ?? null,
        ]);

        return ['failure' => [
            'challenge_attempt' => $challengeAttempt,
            'detected_left' => $selected->rect->left,
            'method' => $selected->method,
            'msg' => (string) ($response['msg'] ?? 'checkImage rejected'),
        ]];
    }

    /** @return list<DetectionCandidate> */
    private function detectCandidates(CaptchaChallenge $challenge, bool $debug): array
    {
        $candidates = [];

        foreach ($this->matchTemplateCandidates($challenge->bigImage, $challenge->smallImage, $challenge->height) as $candidate) {
            $candidates[] = $candidate;
        }

        $imageBox = $this->detectSquareFromBinaryWithHint($this->decodeBase64Image($challenge->bigImage), $challenge->height);
        if ($imageBox !== null && $imageBox->left > 0) {
            $candidates[] = new DetectionCandidate('image', $imageBox, 0.82, [
                'area' => $imageBox->area,
            ]);
        } elseif ($imageBox !== null) {
            Debug::log($debug, 'step=detect rejected_suspicious_left', [
                'method' => 'image',
                'left' => $imageBox->left,
                'top' => $imageBox->top,
            ]);
        }

        $estimate = $this->estimateGapCandidate($challenge->bigImage, $challenge->height);
        if ($estimate->rect->left > 0) {
            $candidates[] = $estimate;
        }

        $candidates = $this->deduplicateCandidates($candidates);
        return $this->rankCandidates($candidates);
    }

    private function detectSquareBase64WithHint(string $encoded, int $topHint): Rect
    {
        $imageData = $this->decodeBase64Image($encoded);
        $box = $this->detectSquareFromBinaryWithHint($imageData, $topHint);
        if ($box !== null) {
            return $box;
        }

        if ($topHint >= 0) {
            $img = imagecreatefromstring($imageData);
            if (!$img instanceof GdImage) {
                throw new UpstreamException('decode image failed', 'upstream query failed');
            }

            return $this->estimateGapFromHint($img, $topHint);
        }

        throw new UpstreamException('captcha square not found', 'upstream query failed');
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

    /** @return list<DetectionCandidate> */
    private function matchTemplateCandidates(string $bigEncoded, string $smallEncoded, int $topHint): array
    {
        if ($smallEncoded === '') {
            return [];
        }

        $big = imagecreatefromstring($this->decodeBase64Image($bigEncoded));
        $small = imagecreatefromstring($this->decodeBase64Image($smallEncoded));
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

        $bestContrast = null;
        $bestContent = null;
        $maxLeft = max(0, $bigWidth - $smallWidth);
        for ($top = $startTop; $top <= $endTop; $top++) {
            for ($left = 0; $left <= $maxLeft; $left++) {
                $contrast = $this->templateContrastScore($big, $small, $left, $top, $smallWidth, $smallHeight);
                if ($bestContrast === null || $contrast > $bestContrast['score']) {
                    $bestContrast = ['left' => $left, 'top' => $top, 'score' => $contrast];
                }

                $content = $this->templateContentScore($big, $small, $left, $top, $smallWidth, $smallHeight);
                if ($bestContent === null || $content < $bestContent['score']) {
                    $bestContent = ['left' => $left, 'top' => $top, 'score' => $content];
                }
            }
        }

        $candidates = [];
        if (is_array($bestContrast) && $bestContrast['left'] > 0) {
            $candidates[] = new DetectionCandidate(
                'template-contrast',
                new Rect($bestContrast['left'], $bestContrast['top'], $bestContrast['left'] + $smallWidth - 1, $bestContrast['top'] + $smallHeight - 1, $smallWidth * $smallHeight),
                0.78,
                ['raw_score' => round($bestContrast['score'], 4)]
            );
        }

        if (is_array($bestContent) && $bestContent['left'] > 0) {
            $candidates[] = new DetectionCandidate(
                'template-content',
                new Rect($bestContent['left'], $bestContent['top'], $bestContent['left'] + $smallWidth - 1, $bestContent['top'] + $smallHeight - 1, $smallWidth * $smallHeight),
                0.74,
                ['raw_score' => round($bestContent['score'], 4)]
            );
        }

        return $candidates;
    }

    private function templateContrastScore(GdImage $big, GdImage $small, int $offsetX, int $offsetY, int $width, int $height): float
    {
        $opaqueScore = 0.0;
        $shellScore = 0.0;
        $opaqueSamples = 0;
        $shellSamples = 0;

        for ($y = 0; $y < $height; $y += self::TEMPLATE_SAMPLE_STEP) {
            for ($x = 0; $x < $width; $x += self::TEMPLATE_SAMPLE_STEP) {
                $smallColor = imagecolorat($small, $x, $y);
                $alpha = ($smallColor >> 24) & 0x7F;
                $bigRgb = $this->rgbAt($big, $offsetX + $x, $offsetY + $y);
                $gapScore = $this->gapPixelScore($bigRgb);
                if ($alpha <= self::TEMPLATE_ALPHA_THRESHOLD) {
                    $opaqueScore += $gapScore;
                    $opaqueSamples++;
                } else {
                    $shellScore += $gapScore;
                    $shellSamples++;
                }
            }
        }

        if ($opaqueSamples === 0) {
            return -INF;
        }

        $opaqueAvg = $opaqueScore / $opaqueSamples;
        $shellAvg = $shellSamples > 0 ? ($shellScore / $shellSamples) : 0.0;
        return $opaqueAvg - ($shellAvg * 0.65);
    }

    private function templateContentScore(GdImage $big, GdImage $small, int $offsetX, int $offsetY, int $width, int $height): float
    {
        $score = 0.0;
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

        return $samples > 0 ? ($score / $samples) : INF;
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
        $bestLeft = 5;
        $bestScore = -1;

        for ($left = 5; $left <= max(5, $width - self::GAP_APPROX_SIZE); $left++) {
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

    private function estimateGapCandidate(string $bigEncoded, int $topHint): DetectionCandidate
    {
        $img = imagecreatefromstring($this->decodeBase64Image($bigEncoded));
        if (!$img instanceof GdImage) {
            throw new UpstreamException('decode image failed', 'upstream query failed');
        }

        return new DetectionCandidate('estimate', $this->estimateGapFromHint($img, $topHint), 0.32, [
            'reason' => 'fallback',
        ]);
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

    /** @return list<int> */
    private function candidateOffsets(int $center, int $radius): array
    {
        $seen = [];
        $offsets = [];

        $add = static function (int $value) use (&$seen, &$offsets): void {
            if ($value < 0 || isset($seen[$value])) {
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

    /** @param list<DetectionCandidate> $candidates
     *  @return list<DetectionCandidate>
     */
    private function rankCandidates(array $candidates): array
    {
        usort($candidates, function (DetectionCandidate $left, DetectionCandidate $right): int {
            $priority = ['template-contrast' => 0, 'template-content' => 1, 'image' => 2, 'estimate' => 3];
            $leftPriority = $priority[$left->method] ?? 9;
            $rightPriority = $priority[$right->method] ?? 9;
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return $right->confidence <=> $left->confidence;
        });

        return $candidates;
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
                '#%s:left=%s,msg=%s',
                (string) ($failure['challenge_attempt'] ?? ''),
                (string) ($failure['detected_left'] ?? ''),
                (string) ($failure['msg'] ?? '')
            );
        }

        return implode(';', $parts);
    }

    /** @param list<DetectionCandidate> $candidates */
    private function persistChallengeSamples(CaptchaChallenge $challenge, int $challengeAttempt, DetectionCandidate $selected, array $candidates, bool $debug): void
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
                'right' => $selected->rect->right,
                'bottom' => $selected->rect->bottom,
                'area' => $selected->rect->area,
            ],
            'candidates' => $this->candidateSummaries($candidates),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($metadata)) {
            @file_put_contents($dir . '/metadata.json', $metadata);
        }
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

    private function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }
}
