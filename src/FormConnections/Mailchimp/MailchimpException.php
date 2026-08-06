<?php

namespace Statamic\FormsPro\FormConnections\Mailchimp;

use RuntimeException;

class MailchimpException extends RuntimeException
{
    public function __construct(
        string $message,
        private ?int $retryAfter = null,
        private bool $complianceState = false
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

    public function isComplianceState(): bool
    {
        return $this->complianceState;
    }
}
