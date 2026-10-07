<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\Twilio\Rules\TwilioRecipient;
use Statamic\FormsPro\FormConnectors\Twilio\Rules\TwilioSender;
use Statamic\FormsPro\FormConnectors\Twilio\SendTwilioMessage;
use Statamic\FormsPro\FormConnectors\Twilio\TwilioClient;
use Statamic\FormsPro\FormConnectors\Twilio\TwilioException;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Twilio extends Connector
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.twilio_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/twilio.svg');
    }

    public function isConfigured(): bool
    {
        return config('statamic.forms-pro.connections.twilio.account_sid')
            && config('statamic.forms-pro.connections.twilio.api_key')
            && config('statamic.forms-pro.connections.twilio.api_secret');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        return new SendTwilioMessage($submission, $connection);
    }

    public function render(Form $form): VueComponent
    {
        $senders = [];
        $isConfigured = $this->isConfigured();

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

        return VueComponent::render('twilio-connector', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => collect($form->connections()->get('twilio'))
                ->mapWithKeys(fn (array $connection): array => [
                    $connection['id'] => $fields->addValues($connection)->preProcess()->meta()->all(),
                ])
                ->all(),
            'defaults' => [
                'values' => $fields->values()->all(),
                'meta' => $fields->meta()->all(),
            ],
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    protected function preProcessConnection(array $connection, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues($connection)
            ->preProcess()
            ->values()
            ->all();
    }

    protected function connectionRules(Form $form): array
    {
        return [
            'to' => ['required', new TwilioRecipient($form)],
            'from' => ['required', new TwilioSender],
            'body' => ['required', 'string', 'max:1600'],
            'country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/'],
        ];
    }

    protected function processConnection(array $connection, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues($connection)
            ->process()
            ->values()
            ->all();
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
                                        'instructions' => __('forms-pro::messages.twilio_to_instructions'),
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
                                        'instructions' => __('forms-pro::messages.twilio_country_code_instructions'),
                                        'placeholder' => '+44',
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'from',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Sender'),
                                        'instructions' => __('forms-pro::messages.twilio_from_instructions'),
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
                                        'instructions' => __('forms-pro::messages.twilio_body_instructions'),
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
}
