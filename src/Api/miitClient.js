import https from 'node:https';
import { URL } from 'node:url';
import { AppConfig } from '../Config/appConfig.js';
import { MiitException, UpstreamException } from '../Exception/miitException.js';
import { DetailSanitizer } from '../Support/detailSanitizer.js';

const BASE_URL = 'https://hlwicpfwc.miit.gov.cn/icpproject_query/api/';
const SERVICE_HOST = 'hlwicpfwc.miit.gov.cn';
const DEFAULT_ORIGIN = 'https://beian.miit.gov.cn';
const DEFAULT_REFERER = 'https://beian.miit.gov.cn/';
const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0';
const DEFAULT_ACCEPT = 'application/json, text/plain, */*';
const DEFAULT_LANGUAGE = 'zh-CN,zh-HK;q=0.9,zh;q=0.8,en-US;q=0.7,en;q=0.6';

export class MiitClient {
  constructor(timeoutSeconds = 15) {
    this.timeoutSeconds = timeoutSeconds;
    this.config = new AppConfig();
    this.cookies = new Map();
    this.headers = new Map([
      ['Host', SERVICE_HOST],
      ['Origin', DEFAULT_ORIGIN],
      ['Referer', DEFAULT_REFERER],
      ['User-Agent', DEFAULT_USER_AGENT],
      ['Accept', DEFAULT_ACCEPT],
      ['Accept-Language', DEFAULT_LANGUAGE],
      ['Connection', 'keep-alive'],
    ]);
  }

  setHeader(key, value) {
    if (value === '') {
      this.headers.delete(key);
      return;
    }
    this.headers.set(key, value);
  }

  setToken(token) {
    this.setHeader('Token', token);
  }

  setSign(sign) {
    this.setHeader('Sign', sign);
  }

  setUuid(uuid) {
    this.setHeader('Uuid', uuid);
  }

  async postFormJson(path, form) {
    const body = new URLSearchParams(form).toString();
    const response = await this.request('POST', path, body, 'application/x-www-form-urlencoded');
    return decodeJsonResponse(response);
  }

  async postJson(path, payload) {
    const response = await this.postJsonRaw(path, payload);
    return decodeJsonResponse(response);
  }

  async postJsonRaw(path, payload) {
    let body;
    try {
      body = JSON.stringify(payload);
    } catch (error) {
      throw new MiitException('failed to encode JSON payload', '', { cause: error });
    }

    return this.request('POST', path, body, 'application/json');
  }

  resolveUrl(path) {
    if (path.startsWith('http://') || path.startsWith('https://')) {
      return path;
    }

    return BASE_URL + path.replace(/^\/+/u, '');
  }

  async request(method, path, body, contentType) {
    const url = new URL(this.resolveUrl(path));
    const headers = Object.fromEntries(this.headers);
    headers['Content-Type'] = contentType;
    headers['Content-Length'] = Buffer.byteLength(body);
    const cookieHeader = this.cookieHeader();
    if (cookieHeader !== '') {
      headers.Cookie = cookieHeader;
    }

    return new Promise((resolve, reject) => {
      const request = https.request({
        protocol: url.protocol,
        hostname: url.hostname,
        port: url.port || 443,
        path: `${url.pathname}${url.search}`,
        method,
        headers,
        timeout: this.timeoutSeconds * 1000,
      }, (response) => {
        this.storeCookies(response.headers['set-cookie']);
        const chunks = [];
        response.on('data', (chunk) => {
          chunks.push(chunk);
        });
        response.on('end', () => {
          const responseBody = Buffer.concat(chunks).toString('utf8');
          if (response.statusCode !== 200) {
            const detail = DetailSanitizer.truncate(`request failed: status=${response.statusCode ?? 0} body=${responseBody.trim()}`, this.config);
            reject(new UpstreamException(detail, 'upstream query failed'));
            return;
          }
          resolve(responseBody);
        });
      });

      request.on('timeout', () => {
        request.destroy(new Error('request timeout'));
      });
      request.on('error', (error) => {
        reject(new UpstreamException(`request failed: ${error.message}`, 'upstream query failed', { cause: error }));
      });
      request.end(body);
    });
  }

  storeCookies(setCookieHeaders) {
    if (!Array.isArray(setCookieHeaders)) {
      return;
    }

    for (const header of setCookieHeaders) {
      const [pair] = header.split(';');
      const equalIndex = pair.indexOf('=');
      if (equalIndex <= 0) {
        continue;
      }
      const name = pair.slice(0, equalIndex).trim();
      const value = pair.slice(equalIndex + 1).trim();
      if (name !== '') {
        this.cookies.set(name, value);
      }
    }
  }

  cookieHeader() {
    return Array.from(this.cookies, ([name, value]) => `${name}=${value}`).join('; ');
  }
}

function decodeJsonResponse(response) {
  try {
    const decoded = JSON.parse(response);
    if (decoded !== null && typeof decoded === 'object' && !Array.isArray(decoded)) {
      return decoded;
    }
  } catch {
    // Handled below to preserve PHP's external error category.
  }

  throw new UpstreamException('failed to decode JSON response', 'upstream query failed');
}
