<?php

declare(strict_types=1);

namespace Miit\Exception;

final class RecordNotFoundException extends MiitException
{
    public function __construct(string $message = '', private readonly bool $cacheable = true, string $userMessage = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $userMessage, $code, $previous);
    }

    public function cacheable(): bool
    {
        return $this->cacheable;
    }
}
