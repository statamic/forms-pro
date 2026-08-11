<?php

namespace Statamic\FormsPro\FormConnections\HubSpot;

use RuntimeException;

class HubSpotException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $retryAfter = null,
        private bool $skippable = false,
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

    public function isSkippable(): bool
    {
        return $this->skippable;
    }
}
