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
            'email' => $this->value($submission, 'email_field'),
            'first_name' => $this->value($submission, 'first_name_field'),
            'last_name' => $this->value($submission, 'last_name_field'),
            'tags' => Arr::get($this->config, 'tags') ?: null,
            'extra_attributes' => $this->extraAttributes(),
            'skip_confirmation' => Arr::get($this->config, 'skip_confirmation') ? true : null,
        ]);
    }

    private function value(Submission $submission, string $key): ?string
    {
        if (! ($handle = Arr::get($this->config, $key))) {
            return null;
        }

        $value = $submission->get($handle);

        return is_scalar($value) ? (string) $value : null;
    }

    private function extraAttributes(): array
    {
        return collect(Arr::get($this->config, 'attributes'))
            ->filter(fn (array $attribute) => filled($attribute['key'] ?? null) && filled($attribute['value'] ?? null))
            ->mapWithKeys(fn (array $attribute) => [$attribute['key'] => $attribute['value']])
            ->all();
    }
}
