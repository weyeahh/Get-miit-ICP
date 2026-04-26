export class ClientIp {
  static detect(request) {
    for (const key of ['x-forwarded-for']) {
      const value = request.headers[key];
      if (typeof value !== 'string' || value === '') {
        continue;
      }

      const first = value.split(',').map((part) => part.trim()).find((part) => part !== '');
      if (first !== undefined) {
        return first;
      }
    }

    return request.socket?.remoteAddress || 'unknown';
  }
}
