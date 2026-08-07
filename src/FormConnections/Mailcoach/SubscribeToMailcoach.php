<?php

namespace Statamic\FormsPro\FormConnections\Mailcoach;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Contracts\Forms\Submission;
use Statamic\Support\Arr;

class SubscribeToMailcoach implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(MailcoachClient $mailcoach): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        try {
            $mailcoach->subscribe($this->config['list'], $this->subscriber($submission));
        } catch (MailcoachException $e) {
            if ($e->isRetryable()) {
                $this->release($e->retryAfter());
            } else {
                $this->fail($e);
            }
        }
    }

    private function subscriber(Submission $submission): array
    {
        return Arr::removeNullValues([
            'email' => $this->value($submission, Arr::get($this->config, 'email_field')),
            'first_name' => $this->value($submission, Arr::get($this->config, 'first_name_field')),
            'last_name' => $this->value($submission, Arr::get($this->config, 'last_name_field')),
            'tags' => Arr::get($this->config, 'tags') ?: null,
            'extra_attributes' => $this->extraAttributes($submission),
            'skip_confirmation' => Arr::get($this->config, 'skip_confirmation') ? true : null,
        ]);
    }

    private function extraAttributes(Submission $submission): array
    {
        $attributes = collect(Arr::get($this->config, 'attributes'))
            ->mapWithKeys(function (array $attribute) use ($submission): array {
                return [$attribute['key'] => $this->value($submission, $attribute['value'] ?? null)];
            });

        return Arr::removeNullValues($attributes->all());
    }

    private function value(Submission $submission, ?string $handle): ?string
    {
        if (! $handle) {
            return null;
        }

        $value = $submission->get($handle);

        return match (true) {
            is_array($value) => implode(', ', Arr::flatten($value)) ?: null,
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
