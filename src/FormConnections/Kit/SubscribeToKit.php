<?php

namespace Statamic\FormsPro\FormConnections\Kit;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\Support\Arr;

class SubscribeToKit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(KitClient $kit): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;
        $email = $this->value($submission, $this->config['email_field']);

        try {
            $response = $kit->subscribe($this->subscriber($submission, $email));

            if ($warnings = Arr::get($response, 'warnings')) {
                Log::info("Kit ignored some custom fields for [{$email}]: ".implode(' ', $warnings));
            }

            if ($form = Arr::get($this->config, 'form')) {
                $kit->addToForm($form, $email);
            }

            foreach (Arr::wrap(Arr::get($this->config, 'tags')) as $tag) {
                $kit->tag($tag, $email);
            }
        } catch (KitException $e) {
            if ($e->isRetryable()) {
                $this->release($e->retryAfter());
            } else {
                $this->fail($e);
            }
        }
    }

    private function subscriber(Submission $submission, string $email): array
    {
        return Arr::removeNullValues([
            'email_address' => $email,
            'first_name' => $this->value($submission, Arr::get($this->config, 'first_name_field')),
            'fields' => $this->customFields($submission) ?: null,
        ]);
    }

    private function customFields(Submission $submission): array
    {
        $customFields = collect(Arr::get($this->config, 'custom_fields'))
            ->mapWithKeys(function (array $customField) use ($submission): array {
                return [$customField['key'] => $this->value($submission, $customField['value'] ?? null)];
            });

        return Arr::removeNullValues($customFields->all());
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
