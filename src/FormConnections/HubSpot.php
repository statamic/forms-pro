<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\FormConnections\HubSpot\HubSpotClient;
use Statamic\FormsPro\FormConnections\HubSpot\HubSpotException;
use Statamic\FormsPro\FormConnections\HubSpot\SubmitToHubSpot;
use Statamic\FormsPro\Http\Controllers\CP\HubSpotConnectionController;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class HubSpot extends Connection
{
    protected static $handle = 'hubspot';

    protected static $title = 'HubSpot';

    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.hubspot_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/hubspot.svg');
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
        if (blank($handle = Arr::get($config, 'consent_field'))) {
            return true;
        }

        $value = $submission->get($handle);

        return match (true) {
            is_bool($value) => $value,
            is_array($value) => filled($value),
            default => filled($value) && ! in_array(Str::lower((string) $value), ['0', 'no', 'off', 'false']),
        };
    }

    private function context(): array
    {
        if (! $request = request()) {
            return [];
        }

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
        $hubspot = app(HubSpotClient::class);
        $account = ['forms' => [], 'subscriptions' => []];
        $isConfigured = (bool) config('forms-pro.connections.hubspot.access_token');

        try {
            if ($isConfigured) {
                $account = Cache::remember("forms-pro.hubspot.account.{$hubspot->fingerprint()}", now()->addMinutes(5), fn (): array => [
                    'forms' => $hubspot->forms(),
                    'subscriptions' => $this->subscriptions($hubspot),
                ]);
            }
        } catch (ConnectionException|HubSpotException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $account['forms'], $account['subscriptions']);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('hubspot', []))
            ->preProcess();

        return VueComponent::render('hubspot-connection', [
            'action' => cp_route('forms.connect.hubspot.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'hubspotForms' => $account['forms'],
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_form_instructions'),
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_email_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_properties_instructions'),
                                        'form' => $form->handle(),
                                        'forms' => $forms,
                                    ],
                                ],
                                [
                                    'handle' => 'consent_to_process_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Consent to Process Data'),
                                        'instructions' => __('forms-pro::messages.hubspot_connection_consent_to_process_instructions'),
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_legitimate_interest_instructions'),
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_communications_instructions'),
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
                                        'instructions' => __('forms-pro::messages.hubspot_connection_consent_field_instructions'),
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

    public function routes(Router $router): void
    {
        $router->patch('/', [HubSpotConnectionController::class, 'update'])->name('update');
    }
}
