import { AppConfig } from '../Config/appConfig.js';

export class DetailSanitizer {
  static truncate(detail, config = new AppConfig()) {
    const max = Math.max(64, config.int('log.max_detail_length'));
    const chars = Array.from(String(detail));
    if (chars.length <= max) {
      return String(detail);
    }

    return `${chars.slice(0, max).join('')}...`;
  }
}
