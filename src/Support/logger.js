import { appendFile, readdir, rm } from 'node:fs/promises';
import path from 'node:path';
import { AppPaths } from './appPaths.js';
import { formatLocalDate, epochSeconds } from './time.js';

const MAX_LOG_DAYS = 7;
const MAX_DEBUG_SAMPLES = 20;
let cleanupRunning = false;

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

  static async cleanup() {
    if (cleanupRunning) {
      return;
    }
    cleanupRunning = true;
    try {
      await cleanOldLogs();
      await cleanDebugSamples();
    } finally {
      cleanupRunning = false;
    }
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

async function cleanOldLogs() {
  try {
    const dir = await AppPaths.ensureDir(AppPaths.storagePath('logs'), true);
    const entries = await readdir(dir, { withFileTypes: true });
    const deadline = epochSeconds() - MAX_LOG_DAYS * 86400;
    for (const entry of entries) {
      if (!entry.isFile() || !entry.name.startsWith('app-') || !entry.name.endsWith('.log')) {
        continue;
      }
      const file = path.join(dir, entry.name);
      try {
        const { stat } = await import('node:fs/promises');
        const info = await stat(file);
        if (Math.floor(info.mtimeMs / 1000) < deadline) {
          await rm(file, { force: true }).catch(() => {});
        }
      } catch {
        // Cleanup is best-effort.
      }
    }
  } catch {
    // Cleanup is best-effort.
  }
}

async function cleanDebugSamples() {
  try {
    const dir = AppPaths.storagePath(path.join('debug', 'captcha'));
    const entries = await readdir(dir, { withFileTypes: true }).catch(() => []);
    const dirs = entries
      .filter((e) => e.isDirectory())
      .sort((a, b) => a.name.localeCompare(b.name));
    while (dirs.length > MAX_DEBUG_SAMPLES) {
      const old = dirs.shift();
      await rm(path.join(dir, old.name), { recursive: true, force: true }).catch(() => {});
    }
  } catch {
    // Cleanup is best-effort.
  }
}
