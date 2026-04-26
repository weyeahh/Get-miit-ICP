import { createApp } from './app.js';

const host = process.env.HOST || '127.0.0.1';
const port = Number.parseInt(process.env.PORT || '8080', 10);

const server = createApp();
server.listen(port, host, () => {
  process.stdout.write(`MIIT ICP service listening on http://${host}:${port}\n`);
});

function shutdown() {
  process.stdout.write('\nShutting down...\n');
  server.close(() => {
    process.stdout.write('Server closed.\n');
    process.exit(0);
  });
  setTimeout(() => {
    process.stderr.write('Forced exit after timeout.\n');
    process.exit(1);
  }, 5000);
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
