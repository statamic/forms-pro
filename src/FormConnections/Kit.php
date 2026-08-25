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
use Statamic\FormsPro\FormConnections\Kit\KitClient;
use Statamic\FormsPro\FormConnections\Kit\KitException;
use Statamic\FormsPro\FormConnections\Kit\SubscribeToKit;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Kit extends Connection
{
    use ReadsSubmissionValues;

    protected $developer = 'Statamic';

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
        return (bool) config('forms-pro.connections.kit.api_key');
    }

    public function finalized(Submission $submission): object|array
    {
        $config = $submission->form()->connections()->get('kit', []);

        if (! Arr::get($config, 'email_field')) {
            return [];
        }

        if (! $submission->get($config['email_field']) || ! $this->hasConsent($submission, $config)) {
            return [];
        }

        return [new SubscribeToKit($submission, $config)];
    }

    private function hasConsent(Submission $submission, array $config): bool
    {
        return blank($handle = Arr::get($config, 'consent_field'))
            || $this->isChecked($submission, $handle);
    }

    public function render(Form $form): VueComponent
    {
        $account = ['forms' => [], 'tags' => [], 'custom_fields' => []];
        $isConfigured = $this->isConfigured();

        try {
            if ($isConfigured) {
                $kit = app(KitClient::class);

                $account = Cache::remember("forms-pro.kit.account.{$kit->fingerprint()}", now()->addMinutes(5), fn (): array => [
                    'forms' => $kit->forms(),
                    'tags' => $kit->tags(),
                    'custom_fields' => $kit->customFields(),
                ]);
            }
        } catch (ConnectionException|KitException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $account['forms'], $account['tags'], $account['custom_fields']);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('kit', []))
            ->preProcess();

        return VueComponent::render('kit-connection', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    public function preProcess(array $config, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues($config)
            ->preProcess()
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'form' => ['nullable', 'integer'],
            'email_field' => ['required', $isFormField],
            'first_name_field' => ['nullable', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'distinct'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.key' => ['required', 'string', 'distinct'],
            'custom_fields.*.value' => ['required', $isFormField],
        ];
    }

    public function process(array $data, Form $form): array
    {
        $config = static::blueprint($form)->fields()
            ->addValues($data)
            ->process()
            ->values()
            ->all();

        $config['custom_fields'] = collect(Arr::get($config, 'custom_fields'))
            ->map(fn (array $customField) => Arr::except($customField, 'id'))
            ->all() ?: null;

        return Arr::removeNullValues($config);
    }

    public static function blueprint(Form $form, array $forms = [], array $tags = [], array $customFields = []): \Statamic\Fields\Blueprint
    {
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
                                        'form' => $form->handle(),
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
                                        'form' => $form->handle(),
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
                                                    'form' => $form->handle(),
                                                    'validate' => ['required'],
                                                    'width' => 50,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Only Subscribe When'),
                                        'instructions' => __('forms-pro::messages.kit_consent_field_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['choice', 'other'],
                                        'clearable' => true,
                                        'width' => 50,
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
                                        'width' => 50,
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
