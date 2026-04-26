import { appendFile } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from './appPaths.js';
import { formatLocalDate } from './time.js';

export class Logger {
  static async debug(message, context = {}) {
    await write('debug', message, context);
  }

  static async error(message, context = {}) {
    await write('error', message, context);
  }

  static async warning(message, context = {}) {
    await write('warning', message, context);
  }
}

async function write(level, message, context) {
  const line = JSON.stringify({
    time: new Date().toISOString(),
    level,
    message,
    context,
  });

  if (line === undefined) {
    return;
  }

  try {
    const dir = await AppPaths.ensureDir(AppPaths.storagePath('logs'), true);
    const file = path.join(dir, `app-${formatLocalDate()}.log`);
    await appendFile(file, `${line}\n`, 'utf8');
  } catch {
    try {
      process.stderr.write(`${line}\n`);
    } catch {
      // Logging is best-effort only.
    }
  }
}
