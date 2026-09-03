<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\Fieldtypes\MailchimpGroupOption;
use Statamic\FormsPro\FormConnections\Concerns\ReadsSubmissionValues;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpException;
use Statamic\FormsPro\FormConnections\Mailchimp\SubscribeToMailchimp;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpGroupsController;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpMarketingPermissionsController;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpMergeFieldsController;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailchimp extends Connection
{
    use ReadsSubmissionValues;

    public const array DEDICATED_MERGE_TAGS = ['FNAME', 'LNAME'];

    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.mailchimp_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailchimp.svg');
    }

    public function darkIcon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailchimp-dark.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.mailchimp.api_key');
    }

    public function finalized(Submission $submission): object|array
    {
        $config = $submission->form()->connections()->get('mailchimp', []);

        if (! Arr::get($config, 'list') || ! Arr::get($config, 'email_field')) {
            return [];
        }

        if (! $submission->get($config['email_field']) || ! $this->hasConsent($submission, $config)) {
            return [];
        }

        return [new SubscribeToMailchimp($submission, $config)];
    }

    private function hasConsent(Submission $submission, array $config): bool
    {
        return blank($handle = Arr::get($config, 'consent_field'))
            || $this->isChecked($submission, $handle);
    }

    public function render(Form $form): VueComponent
    {
        $audiences = [];
        $isConfigured = $this->isConfigured();

        try {
            if ($isConfigured) {
                $audiences = $this->audiences();
            }
        } catch (ConnectionException|MailchimpException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $audiences);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('mailchimp', []))
            ->preProcess();

        return VueComponent::render('mailchimp-connection', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    private function audiences(): array
    {
        $mailchimp = app(MailchimpClient::class);

        return Cache::remember(
            "forms-pro.mailchimp.audiences.{$mailchimp->fingerprint()}",
            now()->addMinutes(5),
            fn () => $mailchimp->audiences()
        );
    }

    public function preProcess(array $config, Form $form): array
    {
        $audiences = [];

        if ($this->isConfigured()) {
            try {
                $audiences = $this->audiences();
            } catch (ConnectionException|MailchimpException) {
            }
        }

        return static::blueprint($form, $audiences)->fields()
            ->addValues($config)
            ->preProcess()
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'list' => ['required', 'alpha_num:ascii'],
            'email_field' => ['required', $isFormField],
            'first_name_field' => ['nullable', $isFormField],
            'last_name_field' => ['nullable', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*.key' => ['required', 'string', 'distinct', 'regex:/^[A-Z0-9_]+$/', Rule::notIn(self::DEDICATED_MERGE_TAGS)],
            'merge_fields.*.value' => ['required', $isFormField],
            'groups' => ['nullable', 'array'],
            'groups.*.group' => ['required', 'string', 'distinct'],
            'groups.*.field' => ['required', $isFormField],
            'groups.*.option' => $this->groupOptionRules($form),
            'marketing_permissions' => ['nullable', 'array'],
            'marketing_permissions.*.permission' => ['required', 'string', 'distinct'],
            'marketing_permissions.*.field' => ['required', $isFormField],
            'skip_confirmation' => ['boolean'],
        ];
    }

    /**
     * A group mapped to a field with options needs to say which one, otherwise it would
     * be applied whenever any option is chosen. Fields without options don't.
     */
    private function groupOptionRules(Form $form): NestedRules
    {
        $fieldsWithOptions = array_keys((new MailchimpGroupOption)->fieldsWithOptions($form));

        return Rule::forEach(fn ($value, $attribute, $data, $group): array => in_array(Arr::get($group, 'field'), $fieldsWithOptions)
            ? ['required', 'string']
            : ['nullable', 'string']);
    }

    public function process(array $data, Form $form): array
    {
        $config = static::blueprint($form)->fields()
            ->addValues($data)
            ->process()
            ->values()
            ->all();

        foreach (['merge_fields', 'groups'] as $grid) {
            $config[$grid] = collect(Arr::get($config, $grid))
                ->map(fn (array $row) => Arr::except($row, 'id'))
                ->all() ?: null;
        }

        return Arr::removeNullValues($config);
    }

    public static function blueprint(Form $form, array $audiences = []): \Statamic\Fields\Blueprint
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
                                        'display' => __('Audience'),
                                        'instructions' => __('forms-pro::messages.mailchimp_list_instructions'),
                                        'options' => $audiences,
                                        'default' => count($audiences) === 1 ? array_key_first($audiences) : null,
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.mailchimp_email_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_tags_instructions'),
                                    ],
                                ],
                                [
                                    'handle' => 'merge_fields',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Merge Fields'),
                                        'instructions' => __('forms-pro::messages.mailchimp_merge_fields_instructions'),
                                        'add_row' => __('Add Merge Field'),
                                        'fullscreen' => false,
                                        'reorderable' => false,
                                        'fields' => [
                                            [
                                                'handle' => 'key',
                                                'field' => [
                                                    'type' => 'mailchimp_merge_field',
                                                    'display' => __('Merge Field'),
                                                    'url' => cp_route('forms.connect.mailchimp.merge-fields', $form->handle()),
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
                                    'handle' => 'groups',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Groups'),
                                        'instructions' => __('forms-pro::messages.mailchimp_groups_instructions'),
                                        'add_row' => __('Add Group'),
                                        'fullscreen' => false,
                                        'reorderable' => false,
                                        'fields' => [
                                            [
                                                'handle' => 'group',
                                                'field' => [
                                                    'type' => 'mailchimp_group',
                                                    'display' => __('Group'),
                                                    'url' => cp_route('forms.connect.mailchimp.groups', $form->handle()),
                                                    'validate' => ['required'],
                                                    'width' => 33,
                                                ],
                                            ],
                                            [
                                                'handle' => 'field',
                                                'field' => [
                                                    'type' => 'form_fields',
                                                    'display' => __('Form Field'),
                                                    'form' => $form->handle(),
                                                    'categories' => ['choice', 'other'],
                                                    'validate' => ['required'],
                                                    'width' => 33,
                                                ],
                                            ],
                                            [
                                                'handle' => 'option',
                                                'field' => [
                                                    'type' => 'mailchimp_group_option',
                                                    'display' => __('When'),
                                                    'form' => $form->handle(),
                                                    'width' => 33,
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'marketing_permissions',
                                    'field' => [
                                        'type' => 'mailchimp_marketing_permissions',
                                        'display' => __('Marketing Permissions'),
                                        'instructions' => __('forms-pro::messages.mailchimp_marketing_permissions_instructions'),
                                        'form' => $form->handle(),
                                        'url' => cp_route('forms.connect.mailchimp.marketing-permissions', $form->handle()),
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Only Subscribe When'),
                                        'instructions' => __('forms-pro::messages.mailchimp_consent_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_skip_confirmation_instructions'),
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

    public function routes(Router $router): void
    {
        $router->get('merge-fields', MailchimpMergeFieldsController::class)->name('merge-fields');
        $router->get('groups', MailchimpGroupsController::class)->name('groups');
        $router->get('marketing-permissions', MailchimpMarketingPermissionsController::class)->name('marketing-permissions');
    }
}
