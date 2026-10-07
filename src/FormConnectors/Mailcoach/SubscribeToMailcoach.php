<?php

namespace Statamic\FormsPro\FormConnectors\Mailcoach;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Contracts\Forms\Submission;
use Statamic\FormsPro\FormConnectors\Concerns\ReadsSubmissionValues;
use Statamic\Support\Arr;

class SubscribeToMailcoach implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ReadsSubmissionValues, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(MailcoachClient $mailcoach): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        if (! $email = $this->value($submission, Arr::get($this->config, 'email_field'))) {
            return;
        }

        try {
            $mailcoach->subscribe($this->config['list'], $this->subscriber($submission, $email));
        } catch (MailcoachException $e) {
            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                default => throw $e,
            };
        }
    }

    private function subscriber(Submission $submission, string $email): array
    {
        return Arr::removeNullValues([
            'email' => $email,
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
}
