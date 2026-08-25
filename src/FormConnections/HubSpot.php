<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\FormConnections\Concerns\ReadsSubmissionValues;
use Statamic\FormsPro\FormConnections\HubSpot\HubSpotClient;
use Statamic\FormsPro\FormConnections\HubSpot\HubSpotException;
use Statamic\FormsPro\FormConnections\HubSpot\SubmitToHubSpot;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class HubSpot extends Connection
{
    use ReadsSubmissionValues;

    protected static $handle = 'hubspot';

    protected static $title = 'HubSpot';

    protected $developer = 'Statamic';

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
        return (bool) config('forms-pro.connections.hubspot.access_token');
    }

    public function finalized(Submission $submission): object|array
    {
        $config = $submission->form()->connections()->get('hubspot', []);

        if (! Arr::get($config, 'form') || ! Arr::get($config, 'email_field')) {
            return [];
        }

        if (! $submission->get($config['email_field']) || ! $this->hasConsent($submission, $config)) {
            return [];
        }

        return [new SubmitToHubSpot($submission, $config, $this->context())];
    }

    private function hasConsent(Submission $submission, array $config): bool
    {
        return blank($handle = Arr::get($config, 'consent_field'))
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
        $account = ['forms' => [], 'subscriptions' => []];
        $isConfigured = $this->isConfigured();

        try {
            if ($isConfigured) {
                $account = $this->account();
            }
        } catch (ConnectionException|HubSpotException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $account['forms'], $account['subscriptions']);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('hubspot', []))
            ->preProcess();

        return VueComponent::render('hubspot-connection', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta()->all(),
            'hubspotForms' => $account['forms'],
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    private function account(): array
    {
        $hubspot = app(HubSpotClient::class);

        return Cache::remember("forms-pro.hubspot.account.{$hubspot->fingerprint()}", now()->addMinutes(5), fn (): array => [
            'forms' => $hubspot->forms(),
            'subscriptions' => $this->subscriptions($hubspot),
        ]);
    }

    private function subscriptions(HubSpotClient $hubspot): array
    {
        try {
            return $hubspot->subscriptions();
        } catch (ConnectionException|HubSpotException) {
            return [];
        }
    }

    public function preProcess(array $config, Form $form): array
    {
        try {
            $account = $this->isConfigured()
                ? $this->account()
                : ['forms' => [], 'subscriptions' => []];
        } catch (ConnectionException|HubSpotException) {
            $account = ['forms' => [], 'subscriptions' => []];
        }

        return static::blueprint($form, $account['forms'], $account['subscriptions'])->fields()
            ->addValues($config)
            ->preProcess()
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'form' => ['required', 'uuid'],
            'email_field' => ['required', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'consent_to_process_field' => ['nullable', $isFormField],
            'legitimate_interest_subscription' => ['nullable', 'integer'],
            'properties' => ['nullable', 'array'],
            'properties.*.key' => ['required', 'string', 'distinct', Rule::notIn(['email'])],
            'properties.*.value' => ['required', $isFormField],
            'communications' => ['nullable', 'array'],
            'communications.*.subscription' => ['required', 'integer', 'distinct'],
            'communications.*.field' => ['required', $isFormField],
        ];
    }

    public function process(array $data, Form $form): array
    {
        $config = static::blueprint($form)->fields()
            ->addValues($data)
            ->process()
            ->values()
            ->all();

        return Arr::removeNullValues($config);
    }

    public static function blueprint(Form $form, array $forms = [], array $subscriptions = []): \Statamic\Fields\Blueprint
    {
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
