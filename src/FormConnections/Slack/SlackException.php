<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use RuntimeException;

class SlackException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?string $error = null,
        private ?int $retryAfter = null
    ) {
        parent::__construct($message);
    }

    public function error(): ?string
    {
        return $this->error;
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
