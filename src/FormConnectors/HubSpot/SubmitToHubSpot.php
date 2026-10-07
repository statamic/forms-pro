<?php

namespace Statamic\FormsPro\FormConnectors\HubSpot;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\FormsPro\FormConnectors\Concerns\ReadsSubmissionValues;
use Statamic\Support\Arr;

class SubmitToHubSpot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ReadsSubmissionValues, SerializesModels;

    private const string CONTACT_OBJECT_TYPE = '0-1';

    public $tries = 5;

    public function __construct(
        public Submission $submission,
        public array $config,
        public array $context = [],
    ) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(HubSpotClient $hubspot): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;
        $email = $this->value($submission, $this->config['email_field']);

        try {
            $hubspot->submit($this->config['form'], $this->payload($hubspot, $submission, $email));
        } catch (HubSpotException $e) {
            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                $e->isSkippable() => Log::info("Skipped submitting [{$email}] to HubSpot: {$e->getMessage()}"),
                default => throw $e,
            };
        }
    }

    private function payload(HubSpotClient $hubspot, Submission $submission, ?string $email): array
    {
        $definition = $hubspot->form($this->config['form']);

        return Arr::removeNullValues([
            'fields' => $this->fields($submission, $email, $definition),
            'submittedAt' => $this->submittedAt($submission),
            'context' => $this->context ?: null,
            'legalConsentOptions' => $this->legalConsentOptions($submission, $definition) ?: null,
        ]);
    }

    private function fields(Submission $submission, ?string $email, array $definition): array
    {
        $objectTypes = collect($definition['fieldGroups'] ?? [])
            ->flatMap(fn (array $group): array => $group['fields'] ?? [])
            ->mapWithKeys(fn (array $field): array => [$field['name'] => $field['objectTypeId']]);

        return collect(Arr::get($this->config, 'properties'))
            ->map(fn (array $property): array => [
                'objectTypeId' => $objectTypes->get($property['key'], self::CONTACT_OBJECT_TYPE),
                'name' => $property['key'],
                'value' => $this->value($submission, $property['value'] ?? null),
            ])
            ->prepend([
                'objectTypeId' => self::CONTACT_OBJECT_TYPE,
                'name' => 'email',
                'value' => $email,
            ])
            ->reject(fn (array $field): bool => blank($field['value']))
            ->values()
            ->all();
    }

    private function submittedAt(Submission $submission): ?string
    {
        $date = $submission->date();

        return $date->greaterThan(now()->subMonth()) ? (string) $date->getTimestampMs() : null;
    }

    private function legalConsentOptions(Submission $submission, array $definition): array
    {
        $consent = $definition['legalConsentOptions'] ?? [];
        $type = $consent['type'] ?? 'none';

        if ($type === 'none') {
            return [];
        }

        if ($type === 'legitimate_interest') {
            return ['legitimateInterest' => Arr::removeNullValues([
                'value' => true,
                'subscriptionTypeId' => $this->legitimateInterestSubscription($consent),
                'legalBasis' => ($consent['lawfulBasis'] ?? null) === 'client' ? 'CUSTOMER' : 'LEAD',
                'text' => $consent['privacyText'] ?? null,
            ])];
        }

        return ['consent' => Arr::removeNullValues([
            'consentToProcess' => $this->consentToProcess($submission, $type),
            'text' => $consent['consentToProcessText'] ?? null,
            'communications' => $this->communications($submission, $consent) ?: null,
        ])];
    }

    private function legitimateInterestSubscription(array $consent): ?int
    {
        $configured = Arr::get($this->config, 'legitimate_interest_subscription');
        $available = array_map('intval', $consent['subscriptionTypeIds'] ?? []);

        if ($configured && in_array((int) $configured, $available, true)) {
            return (int) $configured;
        }

        return count($available) === 1 ? $available[0] : null;
    }

    private function consentToProcess(Submission $submission, string $type): bool
    {
        if ($type === 'implicit_consent_to_process') {
            return true;
        }

        $handle = Arr::get($this->config, 'consent_to_process_field');

        throw_if(blank($handle), new HubSpotException(
            'The HubSpot form requires consent to process data, but no field is mapped to it.'
        ));

        throw_unless($this->isChecked($submission, $handle), new HubSpotException(
            'The submitter didn\'t consent to their data being processed.',
            skippable: true,
        ));

        return true;
    }

    private function communications(Submission $submission, array $consent): array
    {
        $mapped = collect(Arr::get($this->config, 'communications'))
            ->mapWithKeys(fn (array $row): array => [(int) $row['subscription'] => $row['field']]);

        return collect($consent['communicationsCheckboxes'] ?? [])
            ->filter(fn (array $checkbox): bool => $mapped->has((int) $checkbox['subscriptionTypeId']))
            ->map(fn (array $checkbox): array => [
                'value' => $this->isChecked($submission, $mapped->get((int) $checkbox['subscriptionTypeId'])),
                'subscriptionTypeId' => (int) $checkbox['subscriptionTypeId'],
                'text' => $checkbox['label'],
            ])
            ->values()
            ->all();
    }
}
