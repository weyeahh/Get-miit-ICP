export class JsonResponse {
  static send(response, payload, statusCode = 200) {
    let body;
    let finalStatus = statusCode;
    try {
      body = JSON.stringify(payload);
      if (typeof body !== 'string') {
        throw new TypeError('JSON.stringify returned non-string');
      }
    } catch {
      finalStatus = 500;
      body = '{"code":500,"message":"response encoding failed","data":null}';
    }

    response.statusCode = finalStatus;
    response.setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(body);
  }
}
