import https from 'node:https';
import { EnvironmentException } from '../Exception/miitException.js';

export class EnvironmentGuard {
  static assertRuntimeReady() {
    if (typeof https.request !== 'function') {
      throw new EnvironmentException('required module missing: https', 'service environment is not ready');
    }
    if (typeof JSON.parse !== 'function' || typeof JSON.stringify !== 'function') {
      throw new EnvironmentException('required runtime feature missing: json', 'service environment is not ready');
    }
    const [major, minor] = process.versions.node.split('.').map((value) => Number.parseInt(value, 10));
    if (major < 18 || (major === 18 && minor < 18)) {
      throw new EnvironmentException('required node version missing: >=18.18', 'service environment is not ready');
    }
  }

  static async assertSharpReady() {
    try {
      await import('sharp');
    } catch {
      throw new EnvironmentException('required dependency missing: sharp (run npm install)', 'service environment is not ready');
    }
  }
}
