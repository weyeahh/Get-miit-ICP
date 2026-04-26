import test from 'node:test';
import assert from 'node:assert/strict';
import { candidateOffsets } from '../src/Captcha/captchaCore.js';
import { CaptchaSolver } from '../src/Captcha/captchaSolver.js';
import { DetectionCandidate } from '../src/Captcha/detectionCandidate.js';
import { Rect } from '../src/Captcha/rect.js';

test('candidateOffsets expands around center like fuckmiit Go implementation', () => {
  assert.deepEqual(candidateOffsets(80, 4), [80, 79, 81, 78, 82, 77, 83, 76, 84]);
});

test('candidateOffsets deduplicates and keeps non-negative values only', () => {
  assert.deepEqual(candidateOffsets(1, 3), [1, 0, 2, 3, 4]);
});

test('rankCandidates prefers template candidates over fallbacks', () => {
  const solver = new CaptchaSolver(null, null);
  const candidates = solver.rankCandidates([
    new DetectionCandidate('estimate', new Rect(428, 10, 499, 81, 5184), 0.32),
    new DetectionCandidate('image', new Rect(0, 28, 71, 99, 5184), 0.82),
    new DetectionCandidate('template-content', new Rect(172, 28, 243, 99, 5184), 0.74),
    new DetectionCandidate('template-contrast', new Rect(180, 28, 251, 99, 5184), 0.78),
  ]);

  assert.equal(candidates[0].method, 'template-contrast');
});
