<?php

namespace Statamic\FormsPro\FormConnections\Kit;

use RuntimeException;

class KitException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return $this->retryAfter !== null;
    }
}
