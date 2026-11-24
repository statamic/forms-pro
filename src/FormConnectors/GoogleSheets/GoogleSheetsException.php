<?php

namespace Statamic\FormsPro\FormConnectors\GoogleSheets;

use RuntimeException;

class GoogleSheetsException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $retryAfter = null,
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
