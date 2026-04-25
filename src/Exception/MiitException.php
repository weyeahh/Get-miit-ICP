<?php

declare(strict_types=1);

namespace Miit\Exception;

use RuntimeException;

class MiitException extends RuntimeException
{
    public function __construct(string $message = '', private readonly string $userMessage = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function userMessage(): string
    {
        return $this->userMessage !== '' ? $this->userMessage : $this->message;
    }
}
