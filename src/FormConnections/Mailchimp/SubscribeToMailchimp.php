<?php

namespace Statamic\FormsPro\FormConnections\Mailchimp;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class SubscribeToMailchimp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(MailchimpClient $mailchimp): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;
        $email = $this->value($submission, 'email_field');

        try {
            $mailchimp->subscribe($this->config['list'], $this->member($submission, $email));

            if ($tags = Arr::get($this->config, 'tags')) {
                $mailchimp->tag($this->config['list'], $email, $tags);
            }
        } catch (MailchimpException $e) {
            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                $e->isComplianceState() => Log::info("Skipped subscribing [{$email}] to Mailchimp: {$e->getMessage()}"),
                default => $this->fail($e),
            };
        }
    }

    private function member(Submission $submission, string $email): array
    {
        return Arr::removeNullValues([
            'email_address' => $email,
            'status_if_new' => Arr::get($this->config, 'skip_confirmation') ? 'subscribed' : 'pending',
            'merge_fields' => $this->mergeFields($submission) ?: null,
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

    private function mergeFields(Submission $submission): array
    {
        $names = collect([
            'FNAME' => $this->value($submission, 'first_name_field'),
            'LNAME' => $this->value($submission, 'last_name_field'),
        ])->filter();

        return collect(Arr::get($this->config, 'merge_fields'))
            ->filter(fn (array $mergeField): bool => filled($mergeField['key'] ?? null) && filled($mergeField['value'] ?? null))
            ->mapWithKeys(fn (array $mergeField): array => [Str::upper($mergeField['key']) => $mergeField['value']])
            ->merge($names)
            ->all();
    }
}
