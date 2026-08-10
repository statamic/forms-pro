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
        $email = $this->value($submission, $this->config['email_field']);

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
            'interests' => $this->groups($submission) ?: null,
            'marketing_permissions' => $this->marketingPermissions($submission) ?: null,
        ]);
    }

    private function mergeFields(Submission $submission): array
    {
        $mergeFields = collect(Arr::get($this->config, 'merge_fields'))
            ->mapWithKeys(function (array $mergeField) use ($submission): array {
                $mergeTag = Str::upper($mergeField['key']);

                return [$mergeTag => $this->value($submission, $mergeField['value'] ?? null)];
            })
            ->put('FNAME', $this->value($submission, Arr::get($this->config, 'first_name_field')))
            ->put('LNAME', $this->value($submission, Arr::get($this->config, 'last_name_field')));

        return Arr::removeNullValues($mergeFields->all());
    }

    private function groups(Submission $submission): array
    {
        return collect(Arr::get($this->config, 'groups'))
            ->filter(fn (array $group): bool => $this->isSelected($submission, $group))
            ->mapWithKeys(fn (array $group): array => [$group['group'] => true])
            ->all();
    }

    private function isSelected(Submission $submission, array $group): bool
    {
        if (blank($handle = Arr::get($group, 'field'))) {
            return false;
        }

        $value = $submission->get($handle);

        if (blank($option = Arr::get($group, 'option'))) {
            return $this->isChecked($value);
        }

        return in_array($option, Arr::wrap($value));
    }

    private function marketingPermissions(Submission $submission): array
    {
        return collect(Arr::get($this->config, 'marketing_permissions'))
            ->filter(fn (array $permission): bool => filled(Arr::get($permission, 'field')))
            ->map(fn (array $permission): array => [
                'marketing_permission_id' => $permission['permission'],
                'enabled' => $this->isChecked($submission->get($permission['field'])),
            ])
            ->values()
            ->all();
    }

    private function isChecked(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_array($value) => filled($value),
            default => filled($value) && ! in_array(Str::lower((string) $value), ['0', 'no', 'off', 'false']),
        };
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
