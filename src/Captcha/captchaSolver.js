import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { AppConfig } from '../Config/appConfig.js';
import { CaptchaApi } from '../Api/captchaApi.js';
import { UpstreamException } from '../Exception/miitException.js';
import { AppPaths } from '../Support/appPaths.js';
import { Debug } from '../Support/debug.js';
import { formatLocalTimestampCompact } from '../Support/time.js';
import { CaptchaChallenge } from './captchaChallenge.js';
import { DetectionCandidate } from './detectionCandidate.js';
import { Rect } from './rect.js';
import {
  decodeBase64Image,
  estimateGapFromHint,
  findSquareBufferWithHint,
  gapPixelScore,
  sampleGapColor,
  FALLBACK_COLORS,
} from './captchaCore.js';
import { decodeImage } from './imageDecoder.js';

const MAX_CHALLENGE_ATTEMPTS = 5;
const TEMPLATE_TOP_MARGIN = 10;
const TEMPLATE_ALPHA_THRESHOLD = 16;
const TEMPLATE_SAMPLE_STEP = 3;
const TEMPLATE_COARSE_STEP = 12;
const TEMPLATE_FINE_RADIUS = 12;
const TEMPLATE_COARSE_TOP_K = 3;
const MAX_LOGGED_CANDIDATES = 8;
const YIELD_EVERY_ROWS = 5;

export class CaptchaSolver {
  constructor(client, captchaApi) {
    this.client = client;
    this.captchaApi = captchaApi;
    this.config = new AppConfig();
  }

  async solve(captchaUuid, bigImage, smallImage, topHint, debug) {
    let challenge = new CaptchaChallenge(captchaUuid, bigImage, smallImage, topHint);
    const failures = [];

    for (let attempt = 1; attempt <= MAX_CHALLENGE_ATTEMPTS; attempt++) {
      if (attempt > 1) {
        challenge = await this.requestChallenge(debug, attempt);
      }

      const result = await this.trySolveChallenge(challenge, debug, attempt);
      if (result.response !== undefined) {
        return result;
      }

      failures.push(result.failure);
    }

    throw new UpstreamException(`checkImage failed after fresh challenge attempts=${this.formatFailures(failures)}`, 'upstream query failed');
  }

  async trySolveChallenge(challenge, debug, challengeAttempt) {
    const candidates = await this.detectCandidates(challenge, debug);
    if (candidates.length === 0) {
      throw new UpstreamException('captcha candidates are empty', 'upstream query failed');
    }

    const selectedIndex = Math.min(challengeAttempt - 1, candidates.length - 1);
    const selected = candidates[selectedIndex];
    const left = selected.rect.left;

    await Debug.log(debug, `step=detect method=${selected.method} left=${selected.rect.left} top=${selected.rect.top} right=${selected.rect.right} bottom=${selected.rect.bottom}`, {
      challenge_attempt: challengeAttempt,
      selected_candidate_index: selectedIndex,
      selected_confidence: selected.confidence,
      candidates: this.candidateSummaries(candidates),
    });

    await this.persistChallengeSamples(challenge, challengeAttempt, selected, candidates, debug);

    await Debug.log(debug, `step=checkImage attempt_left=${left}`, {
      challenge_attempt: challengeAttempt,
      selected_candidate_index: selectedIndex,
      method: selected.method,
      confidence: selected.confidence,
    });

    const response = await this.captchaApi.tryCheckImage(challenge.uuid, left);
    if ((response.code ?? 0) === 200 && (response.success ?? false) === true) {
      await Debug.log(debug, `step=checkImage success=true sign_len=${String(response.params ?? '').length}`, {
        challenge_attempt: challengeAttempt,
        selected_candidate_index: selectedIndex,
        selected_left: left,
        solved_uuid: challenge.uuid,
      });

      return {
        rect: selected.rect,
        response,
        solvedUuid: challenge.uuid,
      };
    }

    await Debug.log(debug, 'step=checkImage rejected', {
      challenge_attempt: challengeAttempt,
      attempt_left: left,
      selected_candidate_index: selectedIndex,
      method: selected.method,
      confidence: selected.confidence,
      code: response.code ?? null,
      success: response.success ?? null,
      msg: response.msg ?? null,
    });

    return {
      failure: {
        challenge_attempt: challengeAttempt,
        detected_left: selected.rect.left,
        method: selected.method,
        msg: String(response.msg ?? 'checkImage rejected'),
      },
    };
  }

  async detectCandidates(challenge, debug) {
    const candidates = [];

    for (const candidate of await this.matchTemplateCandidates(challenge.bigImage, challenge.smallImage, challenge.height)) {
      candidates.push(candidate);
    }

    let sampledColor = null;
    if (challenge.smallImage !== '') {
      const bigImg = await decodeImage(decodeBase64Image(challenge.bigImage));
      sampledColor = sampleGapColor(bigImg, challenge.height);
    }

    const imageBox = await findSquareBufferWithHint(decodeBase64Image(challenge.bigImage), challenge.height);
    if (imageBox !== null && imageBox.left > 0) {
      candidates.push(new DetectionCandidate('image', imageBox, 0.82, {
        area: imageBox.area,
        sampled: sampledColor !== null,
      }));
    } else if (imageBox !== null) {
      await Debug.log(debug, 'step=detect rejected_suspicious_left', {
        method: 'image',
        left: imageBox.left,
        top: imageBox.top,
      });
    }

    const estimate = await this.estimateGapCandidate(challenge.bigImage, challenge.height);
    if (estimate.rect.left > 0) {
      candidates.push(estimate);
    }

    return this.rankCandidates(this.deduplicateCandidates(candidates));
  }

  async matchTemplateCandidates(bigEncoded, smallEncoded, topHint) {
    if (smallEncoded === '') {
      return [];
    }

    let big;
    let small;
    try {
      big = await decodeImage(decodeBase64Image(bigEncoded));
      small = await decodeImage(decodeBase64Image(smallEncoded));
    } catch {
      return [];
    }

    if (small.width <= 0 || small.height <= 0 || small.width > big.width || small.height > big.height) {
      return [];
    }

    let startTop = Math.max(0, topHint - TEMPLATE_TOP_MARGIN);
    let endTop = Math.min(big.height - small.height, topHint + TEMPLATE_TOP_MARGIN);
    if (endTop < startTop) {
      startTop = 0;
      endTop = Math.max(0, big.height - small.height);
    }

    const maxLeft = Math.max(0, big.width - small.width);

    const coarseContrast = [];
    const coarseContent = [];
    for (let top = startTop; top <= endTop; top += TEMPLATE_COARSE_STEP) {
      for (let left = 0; left <= maxLeft; left += TEMPLATE_COARSE_STEP) {
        const contrast = this.templateContrastScore(big, small, left, top, small.width, small.height);
        coarseContrast.push({ left, top, score: contrast });

        const content = this.templateContentScore(big, small, left, top, small.width, small.height);
        coarseContent.push({ left, top, score: content });
      }
    }

    coarseContrast.sort((a, b) => b.score - a.score);
    coarseContent.sort((a, b) => a.score - b.score);

    const topContrast = coarseContrast.slice(0, TEMPLATE_COARSE_TOP_K);
    const topContent = coarseContent.slice(0, TEMPLATE_COARSE_TOP_K);

    let bestContrast = null;
    let bestContent = null;
    let rowsSinceYield = 0;

    const refine = (coarseList, isContent) => {
      for (const coarse of coarseList) {
        const fineTopStart = Math.max(startTop, coarse.top - TEMPLATE_FINE_RADIUS);
        const fineTopEnd = Math.min(endTop, coarse.top + TEMPLATE_FINE_RADIUS);
        const fineLeftStart = Math.max(0, coarse.left - TEMPLATE_FINE_RADIUS);
        const fineLeftEnd = Math.min(maxLeft, coarse.left + TEMPLATE_FINE_RADIUS);

        for (let top = fineTopStart; top <= fineTopEnd; top += TEMPLATE_SAMPLE_STEP) {
          for (let left = fineLeftStart; left <= fineLeftEnd; left += TEMPLATE_SAMPLE_STEP) {
            if (isContent) {
              const value = this.templateContentScore(big, small, left, top, small.width, small.height);
              if (bestContent === null || value < bestContent.score) {
                bestContent = { left, top, score: value };
              }
            } else {
              const value = this.templateContrastScore(big, small, left, top, small.width, small.height);
              if (bestContrast === null || value > bestContrast.score) {
                bestContrast = { left, top, score: value };
              }
            }
          }
          rowsSinceYield++;
          if (rowsSinceYield >= YIELD_EVERY_ROWS) {
            rowsSinceYield = 0;
          }
        }
      }
    };

    refine(topContrast, false);
    refine(topContent, true);

    const candidates = [];
    if (bestContrast !== null && bestContrast.left > 0) {
      candidates.push(new DetectionCandidate(
        'template-contrast',
        new Rect(bestContrast.left, bestContrast.top, bestContrast.left + small.width - 1, bestContrast.top + small.height - 1, small.width * small.height),
        0.78,
        { raw_score: Number(bestContrast.score.toFixed(4)) },
      ));
    }

    if (bestContent !== null && bestContent.left > 0) {
      candidates.push(new DetectionCandidate(
        'template-content',
        new Rect(bestContent.left, bestContent.top, bestContent.left + small.width - 1, bestContent.top + small.height - 1, small.width * small.height),
        0.74,
        { raw_score: Number(bestContent.score.toFixed(4)) },
      ));
    }

    return candidates;
  }

  templateContrastScore(big, small, offsetX, offsetY, width, height) {
    let opaqueScore = 0;
    let shellScore = 0;
    let opaqueSamples = 0;
    let shellSamples = 0;

    for (let y = 0; y < height; y += TEMPLATE_SAMPLE_STEP) {
      for (let x = 0; x < width; x += TEMPLATE_SAMPLE_STEP) {
        const alpha = small.gdAlphaAt(x, y);
        const gapScore = gapPixelScore(big.rgbAt(offsetX + x, offsetY + y));
        if (alpha <= TEMPLATE_ALPHA_THRESHOLD) {
          opaqueScore += gapScore;
          opaqueSamples++;
        } else {
          shellScore += gapScore;
          shellSamples++;
        }
      }
    }

    if (opaqueSamples === 0) {
      return Number.NEGATIVE_INFINITY;
    }

    const opaqueAvg = opaqueScore / opaqueSamples;
    const shellAvg = shellSamples > 0 ? shellScore / shellSamples : 0;
    return opaqueAvg - shellAvg * 0.65;
  }

  templateContentScore(big, small, offsetX, offsetY, width, height) {
    let score = 0;
    let samples = 0;
    for (let y = 0; y < height; y += TEMPLATE_SAMPLE_STEP) {
      for (let x = 0; x < width; x += TEMPLATE_SAMPLE_STEP) {
        const alpha = small.gdAlphaAt(x, y);
        if (alpha > TEMPLATE_ALPHA_THRESHOLD) {
          continue;
        }

        const smallRgb = small.rgbAt(x, y);
        const bigRgb = big.rgbAt(offsetX + x, offsetY + y);
        score += Math.abs(smallRgb.r - bigRgb.r);
        score += Math.abs(smallRgb.g - bigRgb.g);
        score += Math.abs(smallRgb.b - bigRgb.b);
        samples++;
      }
    }

    return samples > 0 ? score / samples : Number.POSITIVE_INFINITY;
  }

  async estimateGapCandidate(bigEncoded, topHint) {
    const image = await decodeImage(decodeBase64Image(bigEncoded));
    return new DetectionCandidate('estimate', estimateGapFromHint(image, topHint, 0), 0.32, {
      reason: 'fallback',
    });
  }

  rankCandidates(candidates) {
    const priority = new Map([
      ['template-contrast', 0],
      ['template-content', 1],
      ['image', 2],
      ['estimate', 3],
    ]);

    return [...candidates].sort((left, right) => {
      const leftPriority = priority.get(left.method) ?? 9;
      const rightPriority = priority.get(right.method) ?? 9;
      if (leftPriority !== rightPriority) {
        return leftPriority - rightPriority;
      }

      return right.confidence - left.confidence;
    });
  }

  deduplicateCandidates(candidates) {
    const unique = [];
    const seen = new Set();
    for (const candidate of candidates) {
      const key = `${candidate.method}:${candidate.rect.left}:${candidate.rect.top}`;
      if (seen.has(key)) {
        continue;
      }

      seen.add(key);
      unique.push(candidate);
    }

    return unique;
  }

  candidateSummaries(candidates) {
    return candidates.slice(0, MAX_LOGGED_CANDIDATES).map((candidate) => ({
      method: candidate.method,
      left: candidate.rect.left,
      top: candidate.rect.top,
      right: candidate.rect.right,
      bottom: candidate.rect.bottom,
      confidence: candidate.confidence,
      diagnostics: candidate.diagnostics,
    }));
  }

  async requestChallenge(debug, challengeAttempt) {
    const clientUid = CaptchaApi.newClientUid();
    await Debug.log(debug, `step=getCheckImagePoint retry clientUid=${clientUid}`, {
      challenge_attempt: challengeAttempt,
    });

    const challenge = await this.captchaApi.getCheckImagePoint(clientUid);
    const params = challenge.params !== null && typeof challenge.params === 'object' ? challenge.params : {};
    const captchaUuid = String(params.uuid ?? '');
    const bigImage = String(params.bigImage ?? '');
    const smallImage = String(params.smallImage ?? '');
    const height = Number.parseInt(params.height ?? -1, 10);
    if (captchaUuid === '' || bigImage === '' || height < 0) {
      throw new UpstreamException('captcha retry challenge params missing', 'upstream query failed');
    }

    await Debug.log(debug, `step=getCheckImagePoint retry success=true captchaUUID=${captchaUuid} height=${height}`, {
      challenge_attempt: challengeAttempt,
    });

    return new CaptchaChallenge(captchaUuid, bigImage, smallImage, height, clientUid);
  }

  formatFailures(failures) {
    return failures.map((failure) => `#${String(failure.challenge_attempt ?? '')}:left=${String(failure.detected_left ?? '')},msg=${String(failure.msg ?? '')}`).join(';');
  }

  async persistChallengeSamples(challenge, challengeAttempt, selected, candidates, debug) {
    if (!debug || !this.config.bool('debug.store_captcha_samples')) {
      return;
    }

    const safeUuid = challenge.uuid.replace(/[^a-zA-Z0-9_-]+/gu, '-') || 'captcha';
    const dirName = `${formatLocalTimestampCompact()}-${String(challengeAttempt).padStart(2, '0')}-${safeUuid}`;
    const dir = await AppPaths.ensureDir(AppPaths.storagePath(path.join('debug', 'captcha', dirName)), true);
    const big = decodeBase64Image(challenge.bigImage);
    const small = challenge.smallImage === '' ? null : decodeBase64Image(challenge.smallImage);

    await writeFile(path.join(dir, 'big.png'), big);
    if (small !== null) {
      await writeFile(path.join(dir, 'small.png'), small);
    }

    const metadata = {
      uuid: challenge.uuid,
      clientUid: challenge.clientUid,
      height: challenge.height,
      selected: {
        method: selected.method,
        left: selected.rect.left,
        top: selected.rect.top,
        right: selected.rect.right,
        bottom: selected.rect.bottom,
        area: selected.rect.area,
      },
      candidates: this.candidateSummaries(candidates),
    };

    await mkdir(dir, { recursive: true });
    await writeFile(path.join(dir, 'metadata.json'), JSON.stringify(metadata, null, 2), 'utf8');
  }
}
