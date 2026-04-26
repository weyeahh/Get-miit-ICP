import crypto from 'node:crypto';

export function sha1(value) {
  return crypto.createHash('sha1').update(value).digest('hex');
}

export function md5(value) {
  return crypto.createHash('md5').update(value).digest('hex');
}
