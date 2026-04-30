import { createApp } from './app.js';
import { resetStores } from './controllers/queryController.js';

const host = process.env.HOST || '127.0.0.1';
const port = Number.parseInt(process.env.PORT || '8080', 10);

const server = createApp();
server.listen(port, host, () => {
  process.stdout.write(`MIIT ICP service listening on http://${host}:${port}\n`);
});

async function shutdown() {
  process.stdout.write('\nShutting down...\n');

  const forceTimer = setTimeout(() => {
    process.stderr.write('Forced exit after timeout.\n');
    process.exit(1);
  }, 5000);

  try {
    await new Promise((resolve) => server.close(resolve));
    process.stdout.write('HTTP server closed.\n');

    try {
      const { closeRedisClient } = await import('./Storage/redisBackend.js');
      await closeRedisClient();
      process.stdout.write('Redis connection closed.\n');
    } catch {
      // Redis not initialized or already closed
    }

    resetStores();
  } catch (error) {
    process.stderr.write(`Shutdown error: ${error.message}\n`);
  } finally {
    clearTimeout(forceTimer);
    process.exit(0);
  }
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
