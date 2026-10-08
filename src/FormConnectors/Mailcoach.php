<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnectors\Mailcoach\MailcoachException;
use Statamic\FormsPro\FormConnectors\Mailcoach\SubscribeToMailcoach;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailcoach extends Connector
{
    protected $developer = 'Statamic';

    private ?array $lists = null;

    private ?string $apiError = null;

    public function description(): ?string
    {
        return __('forms-pro::messages.mailcoach_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailcoach.svg');
    }

    public function isConfigured(): bool
    {
        return config('statamic.forms-pro.connections.mailcoach.api_url')
            && config('statamic.forms-pro.connections.mailcoach.token');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (! Arr::get($connection, 'list') || ! Arr::get($connection, 'email_field')) {
            return null;
        }

        if (! $submission->get($connection['email_field'])) {
            return null;
        }

        return new SubscribeToMailcoach($submission, $connection);
    }

    public function render(): VueComponent
    {
        return VueComponent::render('mailcoach-connector', [
            ...$this->blueprintProps(),
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function lists(): array
    {
        if ($this->lists !== null || ! $this->isConfigured()) {
            return $this->lists ?? [];
        }

        try {
            $mailcoach = app(MailcoachClient::class);

            return $this->lists = Cache::remember(
                "forms-pro.mailcoach.email-lists.{$mailcoach->fingerprint()}",
                now()->addMinutes(5),
                fn () => $mailcoach->lists()
            );
        } catch (ConnectionException|MailcoachException $e) {
            $this->apiError = $e->getMessage();

            return $this->lists = [];
        }
    }

    protected function connectionRules(): array
    {
        $isFormField = Rule::in($this->form()->formFields()->fields()->keys()->all());

        return [
            'list' => ['uuid'],
            'email_field' => [$isFormField],
            'first_name_field' => [$isFormField],
            'last_name_field' => [$isFormField],
            'tags' => ['array'],
            'tags.*' => ['string'],
            'attributes' => ['array'],
            'attributes.*.key' => ['required', 'string', 'distinct'],
            'attributes.*.value' => ['required', $isFormField],
            'skip_confirmation' => ['boolean'],
        ];
    }

    protected function processConnection(array $connection): array
    {
        $values = parent::processConnection($connection);

        $values['attributes'] = collect(Arr::get($values, 'attributes'))
            ->map(fn (array $attribute) => Arr::except($attribute, 'id'))
            ->all() ?: null;

        return $values;
    }

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        $lists = $this->lists();

        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'list',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Mailcoach List'),
                                        'instructions' => __('forms-pro::messages.mailcoach_list_instructions'),
                                        'options' => $lists,
                                        'default' => count($lists) === 1 ? array_key_first($lists) : null,
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.mailcoach_email_field_instructions'),
                                        'form' => $this->form()->handle(),
                                        'categories' => ['text', 'contact', 'other'],
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'first_name_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('First Name'),
                                        'form' => $this->form()->handle(),
                                        'categories' => ['text', 'contact', 'other'],
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'last_name_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Last Name'),
                                        'form' => $this->form()->handle(),
                                        'categories' => ['text', 'contact', 'other'],
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'tags',
                                    'field' => [
                                        'type' => 'taggable',
                                        'display' => __('Tags'),
                                        'instructions' => __('forms-pro::messages.mailcoach_tags_instructions'),
                                    ],
                                ],
                                [
                                    'handle' => 'attributes',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Attributes'),
                                        'instructions' => __('forms-pro::messages.mailcoach_attributes_instructions'),
                                        'add_row' => __('Add Attribute'),
                                        'fullscreen' => false,
                                        'reorderable' => false,
                                        'fields' => [
                                            [
                                                'handle' => 'key',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => __('Mailcoach Key'),
                                                    'validate' => ['required'],
                                                    'width' => 50,
                                                ],
                                            ],
                                            [
                                                'handle' => 'value',
                                                'field' => [
                                                    'type' => 'form_fields',
                                                    'display' => __('Form Field'),
                                                    'form' => $this->form()->handle(),
                                                    'validate' => ['required'],
                                                    'width' => 50,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'skip_confirmation',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Skip Confirmation Email'),
                                        'instructions' => __('forms-pro::messages.mailcoach_skip_confirmation_instructions'),
                                        'default' => false,
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
