<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\FormConnections\Concerns\ReadsSubmissionValues;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpException;
use Statamic\FormsPro\FormConnections\Mailchimp\SubscribeToMailchimp;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpConnectionController;
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
        return __('forms-pro::messages.mailchimp_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailchimp.svg');
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
        $isConfigured = (bool) config('forms-pro.connections.mailchimp.api_key');

        $audiences = [];

        try {
            if ($isConfigured) {
                $mailchimp = app(MailchimpClient::class);

                $audiences = Cache::remember(
                    "forms-pro.mailchimp.audiences.{$mailchimp->fingerprint()}",
                    now()->addMinutes(5),
                    fn () => $mailchimp->audiences()
                );
            }
        } catch (ConnectionException|MailchimpException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $audiences);

        $fields = $blueprint->fields()
            ->addValues($form->connections()->get('mailchimp', []))
            ->preProcess();

        return VueComponent::render('mailchimp-connection', [
            'action' => cp_route('forms.connect.mailchimp.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_list_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_email_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_tags_instructions'),
                                    ],
                                ],
                                [
                                    'handle' => 'merge_fields',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Merge Fields'),
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_merge_fields_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_groups_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_marketing_permissions_instructions'),
                                        'form' => $form->handle(),
                                        'url' => cp_route('forms.connect.mailchimp.marketing-permissions', $form->handle()),
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'form_fields',
                                        'display' => __('Only Subscribe When'),
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_consent_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_skip_confirmation_instructions'),
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
        $router->patch('/', [MailchimpConnectionController::class, 'update'])->name('update');
    }
}
