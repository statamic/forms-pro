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
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Twilio\SendTwilioMessage;
use Statamic\FormsPro\FormConnections\Twilio\TwilioClient;
use Statamic\FormsPro\FormConnections\Twilio\TwilioException;
use Statamic\FormsPro\Http\Controllers\CP\TwilioConnectionController;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Twilio extends Connection
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.twilio_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/twilio.svg');
    }

    public function count(Form $form): ?int
    {
        return count($form->connections()->get('twilio', []));
    }

    public function finalized(Submission $submission): object|array
    {
        return collect($submission->form()->connections()->get('twilio'))
            ->reject(fn (array $config) => ($config['enabled'] ?? true) === false)
            ->filter(fn (array $config) => ConnectionLogic::passes($config, $submission))
            ->map(fn (array $config) => new SendTwilioMessage($submission, $config))
            ->values()
            ->all();
    }

    public function render(Form $form): VueComponent
    {
        $senders = [];

        $isConfigured = filled(config('forms-pro.connections.twilio.account_sid'))
            && filled(config('forms-pro.connections.twilio.api_key'))
            && filled(config('forms-pro.connections.twilio.api_secret'));

        try {
            if ($isConfigured) {
                $twilio = app(TwilioClient::class);

                $senders = Cache::remember("forms-pro.twilio.account.{$twilio->fingerprint()}", now()->addMinutes(5), function () use ($twilio): array {
                    return [...$twilio->phoneNumbers(), ...$twilio->messagingServices()];
                });
            }
        } catch (ConnectionException|TwilioException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $senders);
        $fields = $blueprint->fields()->preProcess();

        return VueComponent::render('twilio-connection', [
            'action' => cp_route('forms.connect.twilio.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'notifications' => collect($form->connections()->get('twilio'))
                ->mapWithKeys(function (array $config) use ($fields): array {
                    $fields = $fields->addValues($config)->preProcess();

                    return [$config['id'] => [
                        'values' => $fields->values()->all(),
                        'meta' => $fields->meta()->all(),
                    ]];
                })
                ->all(),
            'defaults' => [
                'values' => $fields->values()->all(),
                'meta' => $fields->meta()->all(),
            ],
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    public static function blueprint(Form $form, array $senders = []): \Statamic\Fields\Blueprint
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'to',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Recipient'),
                                        'instructions' => __('forms-pro::messages.twilio_connection_to_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['contact', 'text', 'other'],
                                        'prefix' => 'field:',
                                        'taggable' => true,
                                        'validate' => ['required'],
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'country_code',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Default Country Code'),
                                        'instructions' => __('forms-pro::messages.twilio_connection_country_code_instructions'),
                                        'placeholder' => '+44',
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'from',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Sender'),
                                        'instructions' => __('forms-pro::messages.twilio_connection_from_instructions'),
                                        'options' => $senders,
                                        'default' => count($senders) === 1 ? array_key_first($senders) : null,
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                // TODO: Replace with autocomplete editor once its merged (https://github.com/statamic/cms/pull/15055)
                                [
                                    'handle' => 'body',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => __('Message'),
                                        'instructions' => __('forms-pro::messages.twilio_connection_body_instructions'),
                                        'validate' => ['required', 'max:1600'],
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
        $router->patch('/', [TwilioConnectionController::class, 'update'])->name('update');
    }
}
