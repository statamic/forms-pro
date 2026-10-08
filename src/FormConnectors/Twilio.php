<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

    private ?array $senders = null;

    private ?string $apiError = null;

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

    public function render(): VueComponent
    {
        return VueComponent::render('twilio-connector', [
            ...$this->blueprintProps(),
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function senders(): array
    {
        if ($this->senders !== null || ! $this->isConfigured()) {
            return $this->senders ?? [];
        }

        try {
            $twilio = app(TwilioClient::class);

            return $this->senders = Cache::remember("forms-pro.twilio.account.{$twilio->fingerprint()}", now()->addMinutes(5), function () use ($twilio): array {
                return [...$twilio->phoneNumbers(), ...$twilio->messagingServices()];
            });
        } catch (ConnectionException|TwilioException $e) {
            $this->apiError = $e->getMessage();

            return $this->senders = [];
        }
    }

    protected function connectionRules(): array
    {
        return [
            'to' => [new TwilioRecipient($this->form())],
            'from' => [new TwilioSender],
            'body' => ['string'],
            'country_code' => ['string', 'regex:/^\+[1-9]\d{0,3}$/'],
        ];
    }

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        $senders = $this->senders();

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
                                        'form' => $this->form()->handle(),
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
