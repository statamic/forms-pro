<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\Fieldtypes\MailchimpGroupOption;
use Statamic\FormsPro\FormConnectors\Concerns\ReadsSubmissionValues;
use Statamic\FormsPro\FormConnectors\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnectors\Mailchimp\MailchimpException;
use Statamic\FormsPro\FormConnectors\Mailchimp\SubscribeToMailchimp;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpGroupsController;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpMarketingPermissionsController;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpMergeFieldsController;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailchimp extends Connector
{
    use ReadsSubmissionValues;

    public const array DEDICATED_MERGE_TAGS = ['FNAME', 'LNAME'];

    protected $developer = 'Statamic';

    private ?array $audiences = null;

    private ?string $apiError = null;

    public function description(): ?string
    {
        return __('forms-pro::messages.mailchimp_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailchimp.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.mailchimp.api_key');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (! Arr::get($connection, 'list') || ! Arr::get($connection, 'email_field')) {
            return null;
        }

        if (! $submission->get($connection['email_field']) || ! $this->hasConsent($submission, $connection)) {
            return null;
        }

        return new SubscribeToMailchimp($submission, $connection);
    }

    private function hasConsent(Submission $submission, array $connection): bool
    {
        return blank($handle = Arr::get($connection, 'consent_field'))
            || $this->isChecked($submission, $handle);
    }

    public function render(Form $form): VueComponent
    {
        return VueComponent::render('mailchimp-connector', [
            ...$this->blueprintProps($form, $form->connections()->get('mailchimp', [])),
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function audiences(): array
    {
        if ($this->audiences !== null || ! $this->isConfigured()) {
            return $this->audiences ?? [];
        }

        try {
            $mailchimp = app(MailchimpClient::class);

            return $this->audiences = Cache::remember(
                "forms-pro.mailchimp.audiences.{$mailchimp->fingerprint()}",
                now()->addMinutes(5),
                fn () => $mailchimp->audiences()
            );
        } catch (ConnectionException|MailchimpException $e) {
            $this->apiError = $e->getMessage();

            return $this->audiences = [];
        }
    }

    protected function connectionRules(Form $form): array
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        return [
            'list' => ['alpha_num:ascii'],
            'email_field' => [$isFormField],
            'first_name_field' => [$isFormField],
            'last_name_field' => [$isFormField],
            'consent_field' => [$isFormField],
            'tags' => ['array'],
            'tags.*' => ['string'],
            'merge_fields' => ['array'],
            'merge_fields.*.key' => ['required', 'string', 'distinct', 'regex:/^[A-Z0-9_]+$/', Rule::notIn(self::DEDICATED_MERGE_TAGS)],
            'merge_fields.*.value' => ['required', $isFormField],
            'groups' => ['array'],
            'groups.*.group' => ['required', 'string', 'distinct'],
            'groups.*.field' => ['required', $isFormField],
            'groups.*.option' => $this->groupOptionRules($form),
            'marketing_permissions' => ['array'],
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

    protected function processConnection(array $connection, Form $form): array
    {
        $values = parent::processConnection($connection, $form);

        foreach (['merge_fields', 'groups'] as $grid) {
            $values[$grid] = collect(Arr::get($values, $grid))
                ->map(fn (array $row) => Arr::except($row, 'id'))
                ->all() ?: null;
        }

        return $values;
    }

    public function blueprint(Form $form): \Statamic\Fields\Blueprint
    {
        $audiences = $this->audiences();

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
