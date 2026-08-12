<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use RuntimeException;

class SlackException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $retryAfter = null,
        private ?string $error = null,
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

    public function error(): ?string
    {
        return $this->error;
    }
}
