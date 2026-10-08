<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\Concerns\ReadsSubmissionValues;
use Statamic\FormsPro\FormConnectors\HubSpot\HubSpotClient;
use Statamic\FormsPro\FormConnectors\HubSpot\HubSpotException;
use Statamic\FormsPro\FormConnectors\HubSpot\SubmitToHubSpot;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class HubSpot extends Connector
{
    use ReadsSubmissionValues;

    protected static $handle = 'hubspot';

    protected static $title = 'HubSpot';

    protected $developer = 'Statamic';

    private ?array $account = null;

    private ?string $apiError = null;

    public function description(): ?string
    {
        return __('forms-pro::messages.hubspot_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/hubspot.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.hubspot.access_token');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (! Arr::get($connection, 'form') || ! Arr::get($connection, 'email_field')) {
            return null;
        }

        if (! $submission->get($connection['email_field']) || ! $this->hasConsent($submission, $connection)) {
            return null;
        }

        return new SubmitToHubSpot($submission, $connection, $this->context());
    }

    private function hasConsent(Submission $submission, array $connection): bool
    {
        return blank($handle = Arr::get($connection, 'consent_field'))
            || $this->isChecked($submission, $handle);
    }

    private function context(): array
    {
        $request = request();

        $hutk = $request->cookie('hubspotutk');
        $referer = $request->headers->get('referer');

        return Arr::removeNullValues([
            'hutk' => is_string($hutk) && preg_match('/^[a-f0-9]{32}$/', $hutk) ? $hutk : null,
            'ipAddress' => $request->ip(),
            'pageUri' => filter_var($referer, FILTER_VALIDATE_URL) ?: null,
        ]);
    }

    public function render(Form $form): VueComponent
    {
        return VueComponent::render('hubspot-connector', [
            ...$this->blueprintProps($form, $form->connections()->get('hubspot', [])),
            'hubspotForms' => $this->account()['forms'],
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function account(): array
    {
        if ($this->account !== null || ! $this->isConfigured()) {
            return $this->account ?? ['forms' => [], 'subscriptions' => []];
        }

        try {
            $hubspot = app(HubSpotClient::class);

            return $this->account = Cache::remember("forms-pro.hubspot.account.{$hubspot->fingerprint()}", now()->addMinutes(5), fn (): array => [
                'forms' => $hubspot->forms(),
                'subscriptions' => $this->subscriptions($hubspot),
            ]);
        } catch (ConnectionException|HubSpotException $e) {
            $this->apiError = $e->getMessage();

            return $this->account = ['forms' => [], 'subscriptions' => []];
        }
    }

    private function subscriptions(HubSpotClient $hubspot): array
    {
        try {
            return $hubspot->subscriptions();
        } catch (ConnectionException|HubSpotException) {
            return [];
        }
    }

    protected function connectionRules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'form' => ['uuid'],
            'email_field' => [$isFormField],
            'consent_field' => [$isFormField],
            'consent_to_process_field' => [$isFormField],
            'legitimate_interest_subscription' => ['integer'],
            'properties' => ['array'],
            'properties.*.key' => ['required', 'string', 'distinct', Rule::notIn(['email'])],
            'properties.*.value' => ['required', $isFormField],
            'communications' => ['array'],
            'communications.*.subscription' => ['required', 'integer', 'distinct'],
            'communications.*.field' => ['required', $isFormField],
        ];
    }

    public function blueprint(Form $form): \Statamic\Fields\Blueprint
    {
        ['forms' => $forms, 'subscriptions' => $subscriptions] = $this->account();

        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'form',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('HubSpot Form'),
                                        'instructions' => __('forms-pro::messages.hubspot_form_instructions'),
                                        'options' => collect($forms)->map(fn (array $hubspotForm): string => $hubspotForm['name'])->all(),
                                        'default' => count($forms) === 1 ? array_key_first($forms) : null,
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.hubspot_email_field_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['text', 'contact', 'other'],
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'properties',
                                    'field' => [
                                        'type' => 'hubspot_properties',
                                        'display' => __('Fields'),
                                        'instructions' => __('forms-pro::messages.hubspot_properties_instructions'),
                                        'form' => $form->handle(),
                                        'forms' => $forms,
                                    ],
                                ],
                                [
                                    'handle' => 'consent_to_process_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Consent to Process Data'),
                                        'instructions' => __('forms-pro::messages.hubspot_consent_to_process_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['choice', 'other'],
                                        'clearable' => true,
                                        'if' => ['consent_type' => 'explicit_consent_to_process'],
                                    ],
                                ],
                                [
                                    'handle' => 'legitimate_interest_subscription',
                                    'field' => [
                                        'type' => 'hubspot_subscription',
                                        'display' => __('Legitimate Interest Subscription'),
                                        'instructions' => __('forms-pro::messages.hubspot_legitimate_interest_instructions'),
                                        'forms' => $forms,
                                        'subscriptions' => $subscriptions,
                                        'if' => ['consent_type' => 'legitimate_interest'],
                                    ],
                                ],
                                [
                                    'handle' => 'communications',
                                    'field' => [
                                        'type' => 'hubspot_communications',
                                        'display' => __('Marketing Consent'),
                                        'instructions' => __('forms-pro::messages.hubspot_communications_instructions'),
                                        'form' => $form->handle(),
                                        'forms' => $forms,
                                        'subscriptions' => $subscriptions,
                                        'if' => ['consent_type' => 'contains consent_to_process'],
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Only Submit When'),
                                        'instructions' => __('forms-pro::messages.hubspot_consent_field_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['choice', 'other'],
                                        'clearable' => true,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
