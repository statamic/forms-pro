<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use RuntimeException;

use function Statamic\trans as __;

class SlackRequestException extends RuntimeException
{
    private const RETRYABLE = ['ratelimited', 'internal_error', 'fatal_error'];

    public function __construct(private string $error, private int $retryAfter = 30)
    {
        parent::__construct("Error from Slack: [{$error}].");
    }

    public static function missingToken(): static
    {
        return new static('missing_token');
    }

    public static function rateLimited(int $retryAfter): static
    {
        return new static('ratelimited', $retryAfter);
    }

    public static function fromResponse(array $body): static
    {
        return new static($body['error'] ?? 'unknown_error');
    }

    public function error(): string
    {
        return $this->error;
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function isRetryable(): bool
    {
        return in_array($this->error, self::RETRYABLE);
    }

    public function errorMessage(): string
    {
        $translation = __($key = "forms-pro::messages.slack_error_{$this->error}");

        if ($translation !== $key) {
            return $translation;
        }

        return __('forms-pro::messages.slack_error_unknown', ['error' => $this->error]);
    }
}
