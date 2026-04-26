import { createApp } from './app.js';

const host = process.env.HOST || '127.0.0.1';
const port = Number.parseInt(process.env.PORT || '8080', 10);

const server = createApp();
server.listen(port, host, () => {
  process.stdout.write(`MIIT ICP service listening on http://${host}:${port}\n`);
});
