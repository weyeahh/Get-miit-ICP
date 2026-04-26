import http from 'node:http';
import { handleQuery } from './controllers/queryController.js';
import { JsonResponse } from './Http/jsonResponse.js';
import { Logger } from './Support/logger.js';

export function createApp() {
  return http.createServer((request, response) => {
    handleQuery(request, response).catch(async (error) => {
      await Logger.error('unhandled application error', {
        exception: error?.name ?? 'Error',
        detail: error?.message ?? '',
        stack: error?.stack?.slice(0, 2000) ?? '',
      });

      JsonResponse.send(response, {
        code: 500,
        message: 'internal server error',
        data: {
          domain: '',
          detail: 'the service encountered an internal error',
        },
      }, 500);
      process.stderr.write(`${error?.stack ?? error}\n`);
    });
  });
}
