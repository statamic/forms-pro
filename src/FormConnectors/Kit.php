<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\Kit\KitClient;
use Statamic\FormsPro\FormConnectors\Kit\KitException;
use Statamic\FormsPro\FormConnectors\Kit\SubscribeToKit;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Kit extends Connector
{
    protected $developer = 'Statamic';

    private ?array $account = null;

    private ?string $apiError = null;

    public function description(): ?string
    {
        return __('forms-pro::messages.kit_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/kit.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.kit.api_key');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (! Arr::get($connection, 'email_field')) {
            return null;
        }

        if (! $submission->get($connection['email_field'])) {
            return null;
        }

        return new SubscribeToKit($submission, $connection);
    }

    public function render(): VueComponent
    {
        return VueComponent::render('kit-connector', [
            ...$this->blueprintProps(),
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function account(): array
    {
        if ($this->account !== null || ! $this->isConfigured()) {
            return $this->account ?? ['forms' => [], 'tags' => [], 'custom_fields' => []];
        }

        try {
            $kit = app(KitClient::class);

            return $this->account = Cache::remember("forms-pro.kit.account.{$kit->fingerprint()}", now()->addMinutes(5), fn (): array => [
                'forms' => $kit->forms(),
                'tags' => $kit->tags(),
                'custom_fields' => $kit->customFields(),
            ]);
        } catch (ConnectionException|KitException $e) {
            $this->apiError = $e->getMessage();

            return $this->account = ['forms' => [], 'tags' => [], 'custom_fields' => []];
        }
    }

    protected function connectionRules(): array
    {
        $isFormField = Rule::in($this->form()->formFields()->fields()->keys()->all());

        return [
            'form' => ['integer'],
            'email_field' => [$isFormField],
            'first_name_field' => [$isFormField],
            'tags' => ['array'],
            'tags.*' => ['integer', 'distinct'],
            'custom_fields' => ['array'],
            'custom_fields.*.key' => ['required', 'string', 'distinct'],
            'custom_fields.*.value' => ['required', $isFormField],
        ];
    }

    protected function processConnection(array $connection): array
    {
        $values = parent::processConnection($connection);

        $values['custom_fields'] = collect(Arr::get($values, 'custom_fields'))
            ->map(fn (array $customField) => Arr::except($customField, 'id'))
            ->all() ?: null;

        return $values;
    }

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        ['forms' => $forms, 'tags' => $tags, 'custom_fields' => $customFields] = $this->account();

        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.kit_email_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_first_name_field_instructions'),
                                        'form' => $this->form()->handle(),
                                        'categories' => ['text', 'contact', 'other'],
                                        'clearable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'tags',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Tags'),
                                        'instructions' => __('forms-pro::messages.kit_tags_instructions'),
                                        'options' => $tags,
                                        'multiple' => true,
                                        'clearable' => true,
                                        'searchable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'custom_fields',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Custom Fields'),
                                        'instructions' => __('forms-pro::messages.kit_custom_fields_instructions'),
                                        'add_row' => __('Add Custom Field'),
                                        'fullscreen' => false,
                                        'reorderable' => false,
                                        'fields' => [
                                            [
                                                'handle' => 'key',
                                                'field' => [
                                                    'type' => 'select',
                                                    'display' => __('Custom Field'),
                                                    'options' => $customFields,
                                                    'searchable' => true,
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
                                    'handle' => 'form',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Kit Form'),
                                        'instructions' => __('forms-pro::messages.kit_form_instructions'),
                                        'options' => $forms,
                                        'clearable' => true,
                                        'searchable' => true,
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
