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
use Statamic\FormsPro\FormConnectors\Kit\KitClient;
use Statamic\FormsPro\FormConnectors\Kit\KitException;
use Statamic\FormsPro\FormConnectors\Kit\SubscribeToKit;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Kit extends Connector
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
        return (bool) config('statamic.forms-pro.connections.kit.api_key');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (! Arr::get($connection, 'email_field')) {
            return null;
        }

        if (! $submission->get($connection['email_field']) || ! $this->hasConsent($submission, $connection)) {
            return null;
        }

        return new SubscribeToKit($submission, $connection);
    }

    private function hasConsent(Submission $submission, array $connection): bool
    {
        return blank($handle = Arr::get($connection, 'consent_field'))
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

        $fields = $blueprint->fields()->preProcess();

        return VueComponent::render('kit-connector', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => collect($form->connections()->get('kit'))
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

    protected function processConnection(array $connection, Form $form): array
    {
        $values = static::blueprint($form)->fields()
            ->addValues($connection)
            ->process()
            ->values()
            ->all();

        $values['custom_fields'] = collect(Arr::get($values, 'custom_fields'))
            ->map(fn (array $customField) => Arr::except($customField, 'id'))
            ->all() ?: null;

        return $values;
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
