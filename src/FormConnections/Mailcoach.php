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
use Statamic\FormsPro\FormConnections\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnections\Mailcoach\MailcoachException;
use Statamic\FormsPro\FormConnections\Mailcoach\SubscribeToMailcoach;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailcoach extends Connection
{
    use ReadsSubmissionValues;

    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.mailcoach_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailcoach.svg');
    }

    public function darkIcon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailcoach-dark.svg');
    }

    public function isConfigured(): bool
    {
        return config('statamic.forms-pro.connections.mailcoach.api_url')
            && config('statamic.forms-pro.connections.mailcoach.token');
    }

    public function finalized(Submission $submission): object|array
    {
        $config = $submission->form()->connections()->get('mailcoach', []);

        if (! Arr::get($config, 'list') || ! Arr::get($config, 'email_field')) {
            return [];
        }

        if (! $submission->get($config['email_field']) || ! $this->hasConsent($submission, $config)) {
            return [];
        }

        return [new SubscribeToMailcoach($submission, $config)];
    }

    private function hasConsent(Submission $submission, array $config): bool
    {
        return blank($handle = Arr::get($config, 'consent_field'))
            || $this->isChecked($submission, $handle);
    }

    public function render(Form $form): VueComponent
    {
        $lists = [];
        $isConfigured = $this->isConfigured();

        try {
            if ($isConfigured) {
                $lists = $this->lists();
            }
        } catch (ConnectionException|MailcoachException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $lists);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('mailcoach', []))
            ->preProcess();

        return VueComponent::render('mailcoach-connection', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    private function lists(): array
    {
        $mailcoach = app(MailcoachClient::class);

        return Cache::remember(
            "forms-pro.mailcoach.email-lists.{$mailcoach->fingerprint()}",
            now()->addMinutes(5),
            fn () => $mailcoach->lists()
        );
    }

    public function preProcess(array $config, Form $form): array
    {
        try {
            $lists = $this->isConfigured() ? $this->lists() : [];
        } catch (ConnectionException|MailcoachException) {
            $lists = [];
        }

        return static::blueprint($form, $lists)->fields()
            ->addValues($config)
            ->preProcess()
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'list' => ['required', 'uuid'],
            'email_field' => ['required', $isFormField],
            'first_name_field' => ['nullable', $isFormField],
            'last_name_field' => ['nullable', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.key' => ['required', 'string', 'distinct'],
            'attributes.*.value' => ['required', $isFormField],
            'skip_confirmation' => ['boolean'],
        ];
    }

    public function process(array $data, Form $form): array
    {
        $config = static::blueprint($form)->fields()
            ->addValues($data)
            ->process()
            ->values()
            ->all();

        $config['attributes'] = collect(Arr::get($config, 'attributes'))
            ->map(fn (array $attribute) => Arr::except($attribute, 'id'))
            ->all() ?: null;

        return Arr::removeNullValues($config);
    }

    public static function blueprint(Form $form, array $lists = []): \Statamic\Fields\Blueprint
    {
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
                                        'form' => $form->handle(),
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
                                        'form' => $form->handle(),
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
                                        'instructions' => __('forms-pro::messages.mailcoach_consent_field_instructions'),
                                        'form' => $form->handle(),
                                        'categories' => ['choice', 'other'],
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'skip_confirmation',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Skip Confirmation Email'),
                                        'instructions' => __('forms-pro::messages.mailcoach_skip_confirmation_instructions'),
                                        'default' => false,
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
