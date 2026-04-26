import { Logger } from './logger.js';

export class Debug {
  static async log(enabled, message, context = {}) {
    if (!enabled) {
      return;
    }

    await Logger.debug(message, context);
    let line = `debug: ${message}`;
    if (Object.keys(context).length > 0) {
      line += ` ${JSON.stringify(context)}`;
    }
    process.stderr.write(`${line}\n`);
  }
}
