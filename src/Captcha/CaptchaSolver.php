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
    private const MAX_CHALLENGE_ATTEMPTS = 5;
    private const MIN_VALID_LEFT = 5;
    private const DEFAULT_LEFT = 80;
    private const TEMPLATE_TOP_MARGIN = 10;
    private const TEMPLATE_ALPHA_THRESHOLD = 16;
    private const TEMPLATE_POINT_LIMIT = 420;
    private const TEMPLATE_BORDER_LIMIT = 220;
    private const TEMPLATE_SHELL_LIMIT = 220;
    private const GAP_APPROX_SIZE = 72;
    private const COLOR_TOLERANCE = 12;
    private const RELAXED_COLOR_TOLERANCE = 24;
    private const MIN_COMPONENT_AREA = 900;
    private const MIN_SIDE_LENGTH = 24;
    private const TOP_HINT_ALLOWANCE = 8;
    private const TARGET_R = 199;
    private const TARGET_G = 186;
    private const TARGET_B = 183;
    private const MAX_LOGGED_CANDIDATES = 8;
    private const MAX_DETECTOR_CANDIDATES = 3;
    private const PROBE_SEQUENCE = [0, -1, 1, -2, 2];

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
        $imageSet = $this->decodeChallengeImages($challenge);
        $template = $this->buildTemplate($imageSet);
        $candidates = $this->detectCandidates($imageSet, $template, $challenge->height, $debug);
        if ($candidates === []) {
            throw new UpstreamException('captcha candidates are empty', 'upstream query failed');
        }

        $selected = $candidates[0];
        $probeDelta = self::PROBE_SEQUENCE[min($challengeAttempt - 1, count(self::PROBE_SEQUENCE) - 1)];
        $submittedLeft = $this->clamp($selected->rect->left + $probeDelta, self::MIN_VALID_LEFT, max(self::MIN_VALID_LEFT, $imageSet->bigWidth - 1));

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
            'selected_base_left' => $selected->rect->left,
            'probe_delta' => $probeDelta,
            'submitted_left' => $submittedLeft,
            'candidates' => $this->candidateSummaries($candidates),
        ]);

        $this->persistChallengeSamples($challenge, $challengeAttempt, $candidates, $selected, $submittedLeft, $debug);

        Debug::log($debug, 'step=checkImage attempt_left=' . $submittedLeft, [
            'challenge_attempt' => $challengeAttempt,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
            'base_left' => $selected->rect->left,
            'probe_delta' => $probeDelta,
        ]);

        $response = $this->captchaApi->tryCheckImage($challenge->uuid, $submittedLeft);
        if (($response['code'] ?? 0) === 200 && ($response['success'] ?? false) === true) {
            $rect = new Rect(
                $submittedLeft,
                $selected->rect->top,
                $selected->rect->right + ($submittedLeft - $selected->rect->left),
                $selected->rect->bottom,
                $selected->rect->area
            );

            Debug::log($debug, 'step=checkImage success=true sign_len=' . strlen((string) ($response['params'] ?? '')), [
                'challenge_attempt' => $challengeAttempt,
                'method' => $selected->method,
                'selected_left' => $submittedLeft,
                'base_left' => $selected->rect->left,
                'probe_delta' => $probeDelta,
            ]);

            return ['rect' => $rect, 'response' => $response];
        }

        $failure = [
            'challenge_attempt' => $challengeAttempt,
            'attempt_left' => $submittedLeft,
            'base_left' => $selected->rect->left,
            'probe_delta' => $probeDelta,
            'method' => $selected->method,
            'confidence' => $selected->confidence,
            'code' => $response['code'] ?? null,
            'success' => $response['success'] ?? null,
            'msg' => $response['msg'] ?? null,
        ];

        Debug::log($debug, 'step=checkImage rejected', $failure);

        return ['failure' => $failure];
    }

    private function decodeChallengeImages(CaptchaChallenge $challenge): CaptchaImageSet
    {
        $big = imagecreatefromstring($this->decodeBase64Image($challenge->bigImage));
        if (!$big instanceof GdImage) {
            throw new UpstreamException('decode image failed', 'upstream query failed');
        }

        $small = null;
        $smallWidth = 0;
        $smallHeight = 0;
        if ($challenge->smallImage !== '') {
            $small = imagecreatefromstring($this->decodeBase64Image($challenge->smallImage));
            if ($small instanceof GdImage) {
                $smallWidth = imagesx($small);
                $smallHeight = imagesy($small);
            } else {
                $small = null;
            }
        }

        return new CaptchaImageSet(
            $big,
            $small,
            imagesx($big),
            imagesy($big),
            $smallWidth,
            $smallHeight
        );
    }

    private function buildTemplate(CaptchaImageSet $imageSet): ?CaptchaTemplate
    {
        if (!$imageSet->small instanceof GdImage || $imageSet->smallWidth <= 0 || $imageSet->smallHeight <= 0) {
            return null;
        }

        $opaque = [];
        $border = [];
        $shell = [];
        $occupied = [];
        for ($y = 0; $y < $imageSet->smallHeight; $y++) {
            for ($x = 0; $x < $imageSet->smallWidth; $x++) {
                if (!$this->isTemplateOpaque($imageSet->small, $x, $y)) {
                    continue;
                }

                $opaque[] = ['x' => $x, 'y' => $y];
                $occupied[$y . ':' . $x] = true;
            }
        }

        if ($opaque === []) {
            return null;
        }

        $shellSeen = [];
        foreach ($opaque as $point) {
            $isBorder = false;
            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                $nx = $point['x'] + $dx;
                $ny = $point['y'] + $dy;
                if ($nx < 0 || $nx >= $imageSet->smallWidth || $ny < 0 || $ny >= $imageSet->smallHeight) {
                    $isBorder = true;
                    continue;
                }

                if (!isset($occupied[$ny . ':' . $nx])) {
                    $isBorder = true;
                    $shellKey = $ny . ':' . $nx;
                    if (!isset($shellSeen[$shellKey])) {
                        $shellSeen[$shellKey] = true;
                        $shell[] = ['x' => $nx, 'y' => $ny];
                    }
                }
            }

            if ($isBorder) {
                $border[] = $point;
            }
        }

        return new CaptchaTemplate(
            $imageSet->smallWidth,
            $imageSet->smallHeight,
            $this->reducePoints($opaque, self::TEMPLATE_POINT_LIMIT),
            $this->reducePoints($border === [] ? $opaque : $border, self::TEMPLATE_BORDER_LIMIT),
            $this->reducePoints($shell, self::TEMPLATE_SHELL_LIMIT)
        );
    }

    /** @return list<DetectionCandidate> */
    private function detectCandidates(CaptchaImageSet $imageSet, ?CaptchaTemplate $template, int $topHint, bool $debug): array
    {
        $candidates = [];
        $diagnostics = [];

        if ($template !== null) {
            $detectorResults = [
                'template-gap' => $this->scanTemplateDetector('template-gap', $imageSet, $template, $topHint, fn (int $left, int $top): float => $this->gapTemplateScore($imageSet, $template, $left, $top)),
                'template-contrast' => $this->scanTemplateDetector('template-contrast', $imageSet, $template, $topHint, fn (int $left, int $top): float => $this->contrastTemplateScore($imageSet, $template, $left, $top)),
                'template-content' => $this->scanTemplateDetector('template-content', $imageSet, $template, $topHint, fn (int $left, int $top): float => -$this->contentDifferenceScore($imageSet, $template, $left, $top)),
            ];

            foreach ($detectorResults as $method => $result) {
                $diagnostics[$method] = $result['diagnostics'];
                foreach ($result['candidates'] as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        $imageCandidate = $this->detectImageCandidate($imageSet, $topHint);
        if ($imageCandidate !== null) {
            $candidates[] = $imageCandidate;
        } else {
            $diagnostics['image'] = ['status' => 'not_found'];
        }

        $candidates = $this->deduplicateCandidates($candidates);
        $ranked = $this->rankCandidates($candidates);
        if ($ranked !== []) {
            if ($diagnostics !== []) {
                Debug::log($debug, 'step=detect primary_candidates_ready', [
                    'detectors' => $diagnostics,
                    'candidate_count' => count($ranked),
                ]);
            }

            return $ranked;
        }

        $estimate = $this->estimateGapCandidate($imageSet, $topHint);
        Debug::log($debug, 'step=detect primary_candidates_missing', [
            'detectors' => $diagnostics,
            'fallback' => [
                'left' => $estimate->rect->left,
                'confidence' => $estimate->confidence,
            ],
        ]);

        return [$estimate];
    }

    /**
     * @param callable(int, int): float $scorer
     * @return array{candidates: list<DetectionCandidate>, diagnostics: array<string, mixed>}
     */
    private function scanTemplateDetector(string $method, CaptchaImageSet $imageSet, CaptchaTemplate $template, int $topHint, callable $scorer): array
    {
        $startTop = max(0, $topHint - self::TEMPLATE_TOP_MARGIN);
        $endTop = min($imageSet->bigHeight - $template->height, $topHint + self::TEMPLATE_TOP_MARGIN);
        if ($endTop < $startTop) {
            $startTop = 0;
            $endTop = max(0, $imageSet->bigHeight - $template->height);
        }

        $maxLeft = $imageSet->bigWidth - $template->width;
        if ($maxLeft < self::MIN_VALID_LEFT) {
            return ['candidates' => [], 'diagnostics' => ['status' => 'out_of_bounds']];
        }

        $entries = [];
        $bestScore = -INF;
        $worstScore = INF;

        for ($top = $startTop; $top <= $endTop; $top++) {
            for ($left = self::MIN_VALID_LEFT; $left <= $maxLeft; $left++) {
                $score = $scorer($left, $top);
                if (!is_finite($score)) {
                    continue;
                }

                $bestScore = max($bestScore, $score);
                $worstScore = min($worstScore, $score);
                $entries[] = ['left' => $left, 'top' => $top, 'score' => $score];
            }
        }

        if ($entries === []) {
            return ['candidates' => [], 'diagnostics' => ['status' => 'no_samples']];
        }

        usort($entries, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $topEntries = $this->selectDistinctEntries($entries, self::MAX_DETECTOR_CANDIDATES, max(3, intdiv($template->width, 12)));
        $runnerUp = $entries[1]['score'] ?? $entries[0]['score'];
        $scoreRange = max(0.0001, $bestScore - $worstScore);
        $separation = max(0.0, min(1.0, ($bestScore - $runnerUp) / $scoreRange));

        $candidates = [];
        foreach ($topEntries as $index => $entry) {
            $normalized = max(0.0, min(1.0, ($entry['score'] - $worstScore) / $scoreRange));
            $confidence = min(0.99, 0.25 + ($normalized * 0.55) + (($index === 0 ? $separation : $separation * 0.3) * 0.2));
            $candidates[] = new DetectionCandidate(
                $method,
                new Rect(
                    $entry['left'],
                    $entry['top'],
                    $entry['left'] + $template->width - 1,
                    $entry['top'] + $template->height - 1,
                    $template->width * $template->height
                ),
                $confidence,
                [
                    'raw_score' => round($entry['score'], 4),
                    'normalized_score' => round($normalized, 4),
                    'separation' => round($separation, 4),
                ]
            );
        }

        return [
            'candidates' => $candidates,
            'diagnostics' => [
                'status' => 'ok',
                'best_score' => round($bestScore, 4),
                'runner_up_score' => round($runnerUp, 4),
                'worst_score' => round($worstScore, 4),
                'range' => round($scoreRange, 4),
                'candidate_count' => count($candidates),
            ],
        ];
    }

    private function gapTemplateScore(CaptchaImageSet $imageSet, CaptchaTemplate $template, int $left, int $top): float
    {
        $border = $this->averageGapScore($imageSet->big, $template->borderPoints, $left, $top);
        $opaque = $this->averageGapScore($imageSet->big, $template->opaquePoints, $left, $top);
        $shell = $this->averageGapScore($imageSet->big, $template->shellPoints, $left, $top);

        return ($border * 1.35) + ($opaque * 0.9) - ($shell * 0.45);
    }

    private function contrastTemplateScore(CaptchaImageSet $imageSet, CaptchaTemplate $template, int $left, int $top): float
    {
        $border = $this->averageGapScore($imageSet->big, $template->borderPoints, $left, $top);
        $shell = $this->averageGapScore($imageSet->big, $template->shellPoints, $left, $top);
        $opaqueVariance = $this->averageGradientScore($imageSet->big, $template->opaquePoints, $left, $top);

        return ($border - $shell) + ($opaqueVariance * 0.6);
    }

    private function contentDifferenceScore(CaptchaImageSet $imageSet, CaptchaTemplate $template, int $left, int $top): float
    {
        if (!$imageSet->small instanceof GdImage) {
            return PHP_FLOAT_MAX;
        }

        $score = 0.0;
        $samples = 0;
        foreach ($template->opaquePoints as $point) {
            $smallRgb = $this->rgbAt($imageSet->small, $point['x'], $point['y']);
            $bigRgb = $this->rgbAt($imageSet->big, $left + $point['x'], $top + $point['y']);
            $score += abs($smallRgb['r'] - $bigRgb['r']);
            $score += abs($smallRgb['g'] - $bigRgb['g']);
            $score += abs($smallRgb['b'] - $bigRgb['b']);
            $samples++;
        }

        if ($samples === 0) {
            return PHP_FLOAT_MAX;
        }

        return $score / $samples;
    }

    private function averageGapScore(GdImage $img, array $points, int $left, int $top): float
    {
        if ($points === []) {
            return 0.0;
        }

        $score = 0.0;
        foreach ($points as $point) {
            $score += $this->gapPixelScore($this->rgbAt($img, $left + $point['x'], $top + $point['y']));
        }

        return $score / count($points);
    }

    private function averageGradientScore(GdImage $img, array $points, int $left, int $top): float
    {
        if ($points === []) {
            return 0.0;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        $score = 0.0;
        $samples = 0;
        foreach ($points as $point) {
            $x = $left + $point['x'];
            $y = $top + $point['y'];
            if ($x <= 0 || $y <= 0 || $x >= $width - 1 || $y >= $height - 1) {
                continue;
            }

            $center = $this->lumaAt($img, $x, $y);
            $dx = abs($center - $this->lumaAt($img, $x + 1, $y)) + abs($center - $this->lumaAt($img, $x - 1, $y));
            $dy = abs($center - $this->lumaAt($img, $x, $y + 1)) + abs($center - $this->lumaAt($img, $x, $y - 1));
            $score += ($dx + $dy) / 4;
            $samples++;
        }

        return $samples > 0 ? $score / $samples : 0.0;
    }

    private function detectImageCandidate(CaptchaImageSet $imageSet, int $topHint): ?DetectionCandidate
    {
        foreach ([self::COLOR_TOLERANCE, self::RELAXED_COLOR_TOLERANCE] as $tolerance) {
            $rect = $this->findCaptchaSquare($imageSet->big, $tolerance, $topHint);
            if ($rect !== null && !$this->isSuspiciousLeft($rect->left)) {
                return new DetectionCandidate('image', $rect, $this->imageConfidence($rect), [
                    'area' => $rect->area,
                    'tolerance' => $tolerance,
                ]);
            }
        }

        return null;
    }

    private function estimateGapCandidate(CaptchaImageSet $imageSet, int $topHint): DetectionCandidate
    {
        $rect = $this->estimateGapFromHint($imageSet->big, $imageSet->bigWidth, $imageSet->bigHeight, $topHint);
        return new DetectionCandidate('estimate', $rect, 0.12, [
            'reason' => 'no_primary_candidates',
        ]);
    }

    private function estimateGapFromHint(GdImage $img, int $width, int $height, int $topHint): Rect
    {
        $top = $this->clamp($topHint, 0, max(0, $height - self::GAP_APPROX_SIZE));
        $minLeft = self::MIN_VALID_LEFT;
        $maxLeft = max($minLeft, $width - self::GAP_APPROX_SIZE);
        $bestLeft = $minLeft;
        $bestScore = -1.0;

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

    private function windowScore(GdImage $img, int $left, int $top, int $size, int $width, int $height): float
    {
        $score = 0.0;
        for ($y = $top; $y < min($height, $top + $size); $y++) {
            for ($x = $left; $x < min($width, $left + $size); $x++) {
                $score += $this->gapPixelScore($this->rgbAt($img, $x, $y));
            }
        }

        return $score;
    }

    /** @param list<DetectionCandidate> $candidates
     *  @return list<DetectionCandidate>
     */
    private function rankCandidates(array $candidates): array
    {
        usort($candidates, function (DetectionCandidate $left, DetectionCandidate $right): int {
            $leftScore = $left->confidence + $this->methodBias($left->method);
            $rightScore = $right->confidence + $this->methodBias($right->method);
            $scoreDiff = $rightScore <=> $leftScore;
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }

            return $left->rect->left <=> $right->rect->left;
        });

        return $candidates;
    }

    private function methodBias(string $method): float
    {
        return match ($method) {
            'template-gap' => 0.04,
            'template-contrast' => 0.03,
            'template-content' => 0.02,
            'image' => 0.01,
            'estimate' => 0.0,
            default => 0.0,
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

    /**
     * @param list<array{x:int,y:int}> $points
     * @return list<array{x:int,y:int}>
     */
    private function reducePoints(array $points, int $limit): array
    {
        if (count($points) <= $limit) {
            return $points;
        }

        $step = max(1, (int) ceil(count($points) / $limit));
        $reduced = [];
        foreach ($points as $index => $point) {
            if ($index % $step === 0) {
                $reduced[] = $point;
            }
        }

        return array_slice($reduced, 0, $limit);
    }

    /**
     * @param list<array{left:int,top:int,score:float}> $entries
     * @return list<array{left:int,top:int,score:float}>
     */
    private function selectDistinctEntries(array $entries, int $limit, int $minDeltaLeft): array
    {
        $selected = [];
        foreach ($entries as $entry) {
            $distinct = true;
            foreach ($selected as $picked) {
                if (abs($entry['left'] - $picked['left']) < $minDeltaLeft && abs($entry['top'] - $picked['top']) <= self::TEMPLATE_TOP_MARGIN) {
                    $distinct = false;
                    break;
                }
            }

            if (!$distinct) {
                continue;
            }

            $selected[] = $entry;
            if (count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function isTemplateOpaque(GdImage $img, int $x, int $y): bool
    {
        $color = imagecolorat($img, $x, $y);
        $alpha = ($color >> 24) & 0x7F;

        return $alpha <= self::TEMPLATE_ALPHA_THRESHOLD;
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
                'confidence' => round($candidate->confidence, 4),
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
                '#%s:left=%s,base=%s,delta=%s,method=%s,msg=%s',
                (string) ($failure['challenge_attempt'] ?? ''),
                (string) ($failure['attempt_left'] ?? ''),
                (string) ($failure['base_left'] ?? ''),
                (string) ($failure['probe_delta'] ?? ''),
                (string) ($failure['method'] ?? ''),
                (string) ($failure['msg'] ?? '')
            );
        }

        return implode(';', $parts);
    }

    /** @param list<DetectionCandidate> $candidates */
    private function persistChallengeSamples(CaptchaChallenge $challenge, int $challengeAttempt, array $candidates, DetectionCandidate $selected, int $submittedLeft, bool $debug): void
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
            'submittedLeft' => $submittedLeft,
            'selected' => [
                'method' => $selected->method,
                'baseLeft' => $selected->rect->left,
                'top' => $selected->rect->top,
                'confidence' => $selected->confidence,
            ],
            'candidates' => $this->candidateSummaries($candidates),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (is_string($metadata)) {
            @file_put_contents($dir . '/metadata.json', $metadata);
        }
    }

    private function clamp(int $value, int $low, int $high): int
    {
        return max($low, min($high, $value));
    }

    private function isSuspiciousLeft(int $left): bool
    {
        return $left <= self::MIN_VALID_LEFT;
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

    private function lumaAt(GdImage $img, int $x, int $y): float
    {
        $rgb = $this->rgbAt($img, $x, $y);
        return ($rgb['r'] * 0.299) + ($rgb['g'] * 0.587) + ($rgb['b'] * 0.114);
    }

    private function channelDistance(int $a, int $b): int
    {
        return abs($a - $b);
    }

    /** @param array{r:int,g:int,b:int} $rgb */
    private function gapPixelScore(array $rgb): float
    {
        $maxChannel = max($rgb['r'], $rgb['g'], $rgb['b']);
        $minChannel = min($rgb['r'], $rgb['g'], $rgb['b']);
        $spread = $maxChannel - $minChannel;
        $avg = ($rgb['r'] + $rgb['g'] + $rgb['b']) / 3;

        if ($avg < 95 || $avg > 238) {
            return 0.0;
        }

        $score = max(0.0, 50 - ($spread * 2.2));
        $score += max(0.0, 28 - abs($avg - 189));
        if ($this->channelDistance($rgb['r'], self::TARGET_R) <= self::RELAXED_COLOR_TOLERANCE
            && $this->channelDistance($rgb['g'], self::TARGET_G) <= self::RELAXED_COLOR_TOLERANCE
            && $this->channelDistance($rgb['b'], self::TARGET_B) <= self::RELAXED_COLOR_TOLERANCE) {
            $score += 22;
        }

        return $score;
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

    private function imageConfidence(Rect $rect): float
    {
        $sideWidth = $rect->right - $rect->left + 1;
        $sideHeight = $rect->bottom - $rect->top + 1;
        $sizePenalty = abs(self::GAP_APPROX_SIZE - $sideWidth) + abs(self::GAP_APPROX_SIZE - $sideHeight);

        return max(0.3, 0.82 - ($sizePenalty / 220));
    }
}
