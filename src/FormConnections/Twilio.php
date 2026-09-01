<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Twilio\Rules\TwilioRecipient;
use Statamic\FormsPro\FormConnections\Twilio\Rules\TwilioSender;
use Statamic\FormsPro\FormConnections\Twilio\SendTwilioMessage;
use Statamic\FormsPro\FormConnections\Twilio\TwilioClient;
use Statamic\FormsPro\FormConnections\Twilio\TwilioException;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Twilio extends Connection
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

    public function count(Form $form): ?int
    {
        return count($form->connections()->get('twilio', []));
    }

    public function isConfigured(): bool
    {
        return config('statamic.forms-pro.connections.twilio.account_sid')
            && config('statamic.forms-pro.connections.twilio.api_key')
            && config('statamic.forms-pro.connections.twilio.api_secret');
    }

    public function finalized(Submission $submission): object|array
    {
        return collect($submission->form()->connections()->get('twilio'))
            ->filter(fn (array $config) => ConnectionLogic::passes($config, $submission))
            ->map(fn (array $config) => new SendTwilioMessage($submission, $config))
            ->values()
            ->all();
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

        return VueComponent::render('twilio-connection', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => collect($form->connections()->get('twilio'))
                ->mapWithKeys(fn (array $config): array => [
                    $config['id'] => $fields->addValues($config)->preProcess()->meta()->all(),
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

    public function preProcess(array $config, Form $form): array
    {
        $fields = static::blueprint($form)->fields();

        return collect($config)
            ->map(fn (array $config): array => [
                'id' => $config['id'],
                'enabled' => Arr::get($config, 'enabled') !== false,
                'conditions' => ConnectionLogic::preProcess(Arr::get($config, 'conditions') ?? []),
                ...$fields->addValues($config)->preProcess()->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        return [
            '*' => ['array'],
            '*.to' => ['required', new TwilioRecipient($form)],
            '*.from' => ['required', new TwilioSender],
            '*.body' => ['required', 'string', 'max:1600'],
            '*.country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/'],
            '*.enabled' => ['nullable', 'boolean'],
            '*.conditions' => ['nullable', 'array'],
            '*.conditions.*' => ['array'],
        ];
    }

    public function process(array $data, Form $form): array
    {
        $fields = static::blueprint($form)->fields();

        return collect($data)
            ->map(function (array $config) use ($fields): array {
                $config = Arr::removeNullValues($config);

                $values = $fields
                    ->addValues($config)
                    ->process()
                    ->values()
                    ->all();

                return Arr::removeNullValues([
                    'id' => Arr::get($config, 'id') ?? Str::random(8),
                    ...$values,
                    'enabled' => Arr::get($config, 'enabled') === false ? false : null,
                    'conditions' => ConnectionLogic::process(Arr::get($config, 'conditions') ?? []),
                ]);
            })
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
