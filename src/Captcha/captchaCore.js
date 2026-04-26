import { Rect } from './rect.js';
import { decodeImage } from './imageDecoder.js';
import { UpstreamException } from '../Exception/miitException.js';

export const COLOR_TOLERANCE = 12;
export const RELAXED_COLOR_TOLERANCE = 24;
export const MIN_COMPONENT_AREA = 900;
export const MIN_SIDE_LENGTH = 24;
export const TOP_HINT_ALLOWANCE = 8;
export const GAP_APPROX_SIZE = 72;
export const TARGET_COLOR = { r: 199, g: 186, b: 183 };

export const FALLBACK_COLORS = [
  { r: 199, g: 186, b: 183 },
  { r: 210, g: 195, b: 192 },
  { r: 180, g: 170, b: 168 },
  { r: 192, g: 180, b: 176 },
];

export function sampleGapColor(image, topHint) {
  const top = clamp(topHint, 0, Math.max(0, image.height - GAP_APPROX_SIZE));
  const samples = [];
  for (let y = top; y < Math.min(image.height, top + 32); y++) {
    for (let x = 0; x < image.width; x++) {
      const { r, g, b } = image.rgbAt(x, y);
      const avg = (r + g + b) / 3;
      const spread = Math.max(r, g, b) - Math.min(r, g, b);
      if (avg >= 120 && avg <= 230 && spread <= 30) {
        samples.push({ r, g, b, count: 1 });
      }
    }
  }

  if (samples.length === 0) {
    return null;
  }

  const median = (arr, prop) => {
    const sorted = [...arr].map(v => v[prop]).sort((a, b) => a - b);
    return sorted[Math.floor(sorted.length / 2)];
  };

  const r = median(samples, 'r');
  const g = median(samples, 'g');
  const b = median(samples, 'b');
  return { r, g, b };
}

export async function detectSquareColorAdaptive(image, topHint, sampledColor = null) {
  const targets = sampledColor !== null
    ? [sampledColor, ...FALLBACK_COLORS]
    : FALLBACK_COLORS;

  for (const target of targets) {
    for (const tolerance of [COLOR_TOLERANCE, RELAXED_COLOR_TOLERANCE]) {
      const box = findCaptchaSquare(image, target, tolerance, topHint);
      if (box !== null) {
        return box;
      }
    }
  }

  return null;
}

export async function detectSquareBase64WithHint(encoded, topHint) {
  const imageData = decodeBase64Image(encoded);
  const image = await decodeImage(imageData);

  const box = await detectSquareColorAdaptive(image, topHint, null);
  if (box !== null) {
    return box;
  }

  if (topHint >= 0) {
    return estimateGapFromHint(image, topHint, 0);
  }

  throw new UpstreamException('captcha square not found', 'upstream query failed');
}

export async function findSquareBase64WithHint(encoded, topHint) {
  return findSquareBufferWithHint(decodeBase64Image(encoded), topHint);
}

export async function findSquareBufferWithHint(binary, topHint) {
  const image = await decodeImage(binary);
  return detectSquareColorAdaptive(image, topHint, null);
}

export function decodeBase64Image(encoded) {
  let cleaned = String(encoded).trim();
  const comma = cleaned.indexOf(',');
  if (comma >= 0) {
    cleaned = cleaned.slice(comma + 1);
  }

  const standard = decodeStandardBase64(cleaned);
  if (standard !== null) {
    return standard;
  }

  const raw = decodeRawStandardBase64(cleaned);
  if (raw !== null) {
    return raw;
  }

  const urlSafe = cleaned.replace(/-/gu, '+').replace(/_/gu, '/');
  const urlDecoded = decodeRawStandardBase64(urlSafe) ?? decodeStandardBase64(urlSafe);
  if (urlDecoded !== null) {
    return urlDecoded;
  }

  throw new UpstreamException('unsupported base64 image data', 'upstream query failed');
}

export function findCaptchaSquare(image, target, tolerance, topHint) {
  const width = image.width;
  const height = image.height;
  const visited = new Uint8Array(width * height);
  let best = null;

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const index = y * width + x;
      if (visited[index] === 1) {
        continue;
      }
      visited[index] = 1;

      if (!isNearTarget(image.rgbAt(x, y), target, tolerance)) {
        continue;
      }

      const component = floodFill(image, x, y, target, tolerance, visited);
      if (!looksLikeSquare(component) || !matchesTopHint(component, topHint)) {
        continue;
      }

      if (best === null || component.area > best.area) {
        best = component;
      }
    }
  }

  return best;
}

export function floodFill(image, startX, startY, target, tolerance, visited) {
  const width = image.width;
  const height = image.height;
  const queueX = [startX];
  const queueY = [startY];
  const component = new Rect(startX, startY, startX, startY, 0);

  for (let head = 0; head < queueX.length; head++) {
    const x = queueX[head];
    const y = queueY[head];
    component.area++;
    component.left = Math.min(component.left, x);
    component.top = Math.min(component.top, y);
    component.right = Math.max(component.right, x);
    component.bottom = Math.max(component.bottom, y);

    for (const [dx, dy] of [[-1, 0], [1, 0], [0, -1], [0, 1]]) {
      const nx = x + dx;
      const ny = y + dy;
      if (nx < 0 || nx >= width || ny < 0 || ny >= height) {
        continue;
      }

      const nextIndex = ny * width + nx;
      if (visited[nextIndex] === 1) {
        continue;
      }
      visited[nextIndex] = 1;

      if (!isNearTarget(image.rgbAt(nx, ny), target, tolerance)) {
        continue;
      }

      queueX.push(nx);
      queueY.push(ny);
    }
  }

  return component;
}

export function estimateGapFromHint(image, topHint, startLeft = 0) {
  const top = clamp(topHint, 0, Math.max(0, image.height - GAP_APPROX_SIZE));
  let bestLeft = startLeft;
  let bestScore = -1;
  const maxLeft = Math.max(startLeft, image.width - GAP_APPROX_SIZE);

  for (let left = startLeft; left <= maxLeft; left++) {
    const score = windowScore(image, left, top, GAP_APPROX_SIZE);
    if (score > bestScore) {
      bestScore = score;
      bestLeft = left;
    }
  }

  return new Rect(
    bestLeft,
    top,
    Math.min(image.width - 1, bestLeft + GAP_APPROX_SIZE - 1),
    Math.min(image.height - 1, top + GAP_APPROX_SIZE - 1),
    GAP_APPROX_SIZE * GAP_APPROX_SIZE,
  );
}

export function windowScore(image, left, top, size) {
  let score = 0;
  for (let y = top; y < Math.min(image.height, top + size); y++) {
    for (let x = left; x < Math.min(image.width, left + size); x++) {
      score += gapPixelScore(image.rgbAt(x, y));
    }
  }

  return score;
}

export function gapPixelScore(rgb) {
  const maxChannel = Math.max(rgb.r, rgb.g, rgb.b);
  const minChannel = Math.min(rgb.r, rgb.g, rgb.b);
  const spread = maxChannel - minChannel;
  const avg = Math.trunc((rgb.r + rgb.g + rgb.b) / 3);

  if (avg < 100 || avg > 235) {
    return 0;
  }

  let score = Math.max(0, 45 - spread * 2);
  score += Math.max(0, 30 - Math.abs(avg - 189));
  if (channelDistance(rgb.r, TARGET_COLOR.r) <= RELAXED_COLOR_TOLERANCE
    && channelDistance(rgb.g, TARGET_COLOR.g) <= RELAXED_COLOR_TOLERANCE
    && channelDistance(rgb.b, TARGET_COLOR.b) <= RELAXED_COLOR_TOLERANCE) {
    score += 25;
  }

  return score;
}

export function looksLikeSquare(box) {
  const width = box.right - box.left + 1;
  const height = box.bottom - box.top + 1;
  if (box.area < MIN_COMPONENT_AREA || width < MIN_SIDE_LENGTH || height < MIN_SIDE_LENGTH) {
    return false;
  }

  if (width > height) {
    return width - height <= Math.trunc(width / 3);
  }

  return height - width <= Math.trunc(height / 3);
}

export function matchesTopHint(box, topHint) {
  if (topHint < 0) {
    return true;
  }

  return Math.abs(box.top - topHint) <= TOP_HINT_ALLOWANCE;
}

export function isNearTarget(rgb, target, tolerance) {
  return channelDistance(rgb.r, target.r) <= tolerance
    && channelDistance(rgb.g, target.g) <= tolerance
    && channelDistance(rgb.b, target.b) <= tolerance;
}

export function channelDistance(a, b) {
  return Math.abs(a - b);
}

export function candidateOffsets(center, radius) {
  const seen = new Set();
  const offsets = [];

  const add = (value) => {
    if (value < 0 || seen.has(value)) {
      return;
    }
    seen.add(value);
    offsets.push(value);
  };

  add(center);
  for (let delta = 1; delta <= radius; delta++) {
    add(center - delta);
    add(center + delta);
  }

  return offsets;
}

export function clamp(value, low, high) {
  return Math.max(low, Math.min(high, value));
}

function decodeStandardBase64(value) {
  if (!/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/u.test(value)) {
    return null;
  }
  return Buffer.from(value, 'base64');
}

function decodeRawStandardBase64(value) {
  if (!/^[A-Za-z0-9+/]+$/u.test(value) || value.length % 4 === 1) {
    return null;
  }
  const padded = value.padEnd(value.length + ((4 - (value.length % 4)) % 4), '=');
  return Buffer.from(padded, 'base64');
}
