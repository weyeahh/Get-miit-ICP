import { UpstreamException } from '../Exception/miitException.js';

let sharpFactory = null;

export async function decodeImage(buffer) {
  try {
    if (sharpFactory === null) {
      const mod = await import('sharp');
      sharpFactory = mod.default ?? mod;
    }

    const { data, info } = await sharpFactory(buffer, { failOn: 'none', limitInputPixels: false })
      .ensureAlpha()
      .raw()
      .toBuffer({ resolveWithObject: true });

    return new PixelImage(info.width, info.height, data, info.channels);
  } catch (error) {
    throw new UpstreamException('decode image failed', 'upstream query failed', { cause: error });
  }
}

export class PixelImage {
  constructor(width, height, data, channels) {
    this.width = width;
    this.height = height;
    this.data = data;
    this.channels = channels;
  }

  rgbaAt(x, y) {
    const offset = (y * this.width + x) * this.channels;
    return {
      r: this.data[offset],
      g: this.data[offset + 1],
      b: this.data[offset + 2],
      a: this.data[offset + 3],
    };
  }

  rgbAt(x, y) {
    const color = this.rgbaAt(x, y);
    return {
      r: color.r,
      g: color.g,
      b: color.b,
    };
  }

  gdAlphaAt(x, y) {
    const alpha = this.rgbaAt(x, y).a;
    return Math.round(((255 - alpha) * 127) / 255);
  }
}
