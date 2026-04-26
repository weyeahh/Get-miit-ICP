import { ValidationException } from '../Exception/miitException.js';

export class DomainNormalizer {
  normalize(input) {
    let domain = String(input).trim();
    if (domain === '') {
      throw new ValidationException('domain parameter is required');
    }

    if (/[\x00-\x1F\x7F\s]/u.test(domain)) {
      throw new ValidationException('domain contains invalid characters');
    }

    domain = domain.toLowerCase().replace(/\.+$/u, '');
    if (domain === '') {
      throw new ValidationException('domain parameter is required');
    }

    if (Buffer.byteLength(domain, 'utf8') > 253) {
      throw new ValidationException('domain is too long');
    }

    if (domain.includes('..')) {
      throw new ValidationException('domain format is invalid');
    }

    if (!/^[a-z0-9.-]+$/u.test(domain)) {
      throw new ValidationException('domain format is invalid');
    }

    const labels = domain.split('.');
    if (labels.length < 2) {
      throw new ValidationException('domain format is invalid');
    }

    for (const label of labels) {
      if (label === '' || Buffer.byteLength(label, 'utf8') > 63) {
        throw new ValidationException('domain format is invalid');
      }
      if (label.startsWith('-') || label.endsWith('-')) {
        throw new ValidationException('domain format is invalid');
      }
    }

    return domain;
  }
}
