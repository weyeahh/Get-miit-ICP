import { EnvironmentException } from '../Exception/miitException.js';

export class EnvironmentGuard {
  static assertRuntimeReady() {
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
