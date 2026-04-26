import crypto from 'node:crypto';
import { UpstreamException } from '../Exception/miitException.js';

export class CaptchaApi {
  constructor(client) {
    this.client = client;
  }

  async getCheckImagePoint(clientUid) {
    const response = await this.client.postJson('image/getCheckImagePoint', {
      clientUid,
    });

    if ((response.code ?? 0) !== 200 || (response.success ?? false) !== true) {
      throw new UpstreamException(`getCheckImagePoint rejected: code=${String(response.code ?? '')} msg=${String(response.msg ?? '')}`, 'upstream query failed');
    }

    return response;
  }

  async tryCheckImage(key, value) {
    return this.client.postJson('image/checkImage', {
      key,
      value: String(value),
    });
  }

  static newClientUid() {
    const raw = crypto.randomBytes(16);
    raw[6] = (raw[6] & 0x0f) | 0x40;
    raw[8] = (raw[8] & 0x3f) | 0x80;
    const hex = raw.toString('hex');
    return `point-${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
  }
}
