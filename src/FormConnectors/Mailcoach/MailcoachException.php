<?php

namespace Statamic\FormsPro\FormConnectors\Mailcoach;

use RuntimeException;

class MailcoachException extends RuntimeException
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
