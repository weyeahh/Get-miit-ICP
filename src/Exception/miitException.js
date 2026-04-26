export class MiitException extends Error {
  constructor(message = '', userMessage = '', options = {}) {
    super(message, options);
    this.name = new.target.name;
    this._userMessage = userMessage;
  }

  userMessage() {
    return this._userMessage !== '' ? this._userMessage : this.message;
  }
}

export class ValidationException extends MiitException {}

export class RateLimitException extends MiitException {}

export class StorageException extends MiitException {}

export class UpstreamException extends MiitException {}

export class EnvironmentException extends MiitException {}

export class InternalErrorException extends MiitException {}

export class RecordNotFoundException extends MiitException {
  constructor(message = '', cacheable = true, userMessage = '', options = {}) {
    super(message, userMessage, options);
    this._cacheable = cacheable;
  }

  cacheable() {
    return this._cacheable;
  }
}
