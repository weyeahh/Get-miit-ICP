import { md5 } from '../Support/hash.js';
import { UpstreamException } from '../Exception/miitException.js';

const AUTH_SECRET = 'testtest';

export class AuthApi {
  constructor(client) {
    this.client = client;
  }

  async auth(timestamp) {
    const response = await this.client.postFormJson('auth', {
      authKey: AuthApi.buildAuthKey(timestamp),
      timeStamp: String(timestamp),
    });

    if ((response.code ?? 0) !== 200 || (response.success ?? false) !== true) {
      throw new UpstreamException(`auth request rejected: code=${String(response.code ?? '')} msg=${String(response.msg ?? '')}`, 'upstream query failed');
    }

    const business = String(response.params?.bussiness ?? '');
    if (business === '') {
      throw new UpstreamException('auth response missing token', 'upstream query failed');
    }

    this.client.setToken(business);
    return response;
  }

  static buildAuthKey(timestamp) {
    return md5(`${AUTH_SECRET}${timestamp}`);
  }
}
