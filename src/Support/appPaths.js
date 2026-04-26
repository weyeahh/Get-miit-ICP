import { access, mkdir } from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { EnvironmentException } from '../Exception/miitException.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');

export class AppPaths {
  static projectRoot() {
    return root;
  }

  static storagePath(suffix = '') {
    const base = path.join(root, 'storage');
    return suffix === '' ? base : path.join(base, suffix);
  }

  static async ensureDir(directory, requireWritable = false) {
    try {
      await mkdir(directory, { recursive: true, mode: 0o777 });
      if (requireWritable) {
        await access(directory, constants.W_OK);
      }
      return directory;
    } catch (error) {
      throw new EnvironmentException(`failed to create or access directory: ${directory}`, 'service environment is not ready', { cause: error });
    }
  }
}
