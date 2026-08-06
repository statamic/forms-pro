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
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpException;
use Statamic\FormsPro\FormConnections\Mailchimp\SubscribeToMailchimp;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpConnectionController;
use Statamic\FormsPro\Http\Controllers\CP\MailchimpMergeFieldsController;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailchimp extends Connection
{
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

    public function count(Form $form): ?int
    {
        return filled($form->connections()->get('mailchimp')) ? 1 : 0;
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
        if (blank($handle = Arr::get($config, 'consent_field'))) {
            return true;
        }

        $value = $submission->get($handle);

        return match (true) {
            is_bool($value) => $value,
            is_array($value) => filled($value),
            default => filled($value) && ! in_array(Str::lower((string) $value), ['0', 'no', 'off', 'false']),
        };
    }

    public function render(Form $form): VueComponent
    {
        $isConfigured = (bool) config('forms-pro.connections.mailchimp.api_key');

        try {
            $audiences = $isConfigured
                ? Cache::remember(
                    'forms-pro.mailchimp.audiences',
                    now()->addMinutes(5),
                    fn () => app(MailchimpClient::class)->audiences()
                )
                : [];
        } catch (ConnectionException|MailchimpException $e) {
            $audiences = [];
            $apiError = $e->getMessage();
        }

        $fields = static::blueprint($form, $audiences)->fields()
            ->addValues($form->connections()->get('mailchimp', []))
            ->preProcess();

        return VueComponent::render('mailchimp-connection', [
            'action' => cp_route('forms.connect.mailchimp.update', $form->handle()),
            'blueprint' => static::blueprint($form, $audiences)->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    public static function blueprint(Form $form, array $audiences = []): \Statamic\Fields\Blueprint
    {
        $textFields = static::formFieldOptions($form, ['text', 'contact']);
        $choiceFields = static::formFieldOptions($form, ['choice']);

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
                                        'type' => 'select',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_email_field_instructions'),
                                        'options' => $textFields,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'first_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('First Name'),
                                        'options' => $textFields,
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'last_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Last Name'),
                                        'options' => $textFields,
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
                                        'fields' => [
                                            [
                                                'handle' => 'key',
                                                'field' => [
                                                    'type' => 'mailchimp_merge_field',
                                                    'display' => __('Merge Field'),
                                                    'url' => cp_route('forms.connect.mailchimp.merge-fields', $form->handle()),
                                                    'validate' => ['required'],
                                                ],
                                            ],
                                            // TODO: Replace with autocomplete editor once its merged (https://github.com/statamic/cms/pull/15055)
                                            [
                                                'handle' => 'value',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => __('Value'),
                                                    'validate' => ['required'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'consent_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Consent'),
                                        'instructions' => __('forms-pro::messages.mailchimp_connection_consent_field_instructions'),
                                        'options' => $choiceFields,
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

    private static function formFieldOptions(Form $form, array $categories): array
    {
        return $form->formFields()->fields()
            ->filter(fn ($field) => ! $field->fieldtype()->categories() || array_intersect($field->fieldtype()->categories(), $categories))
            ->mapWithKeys(fn ($field) => [$field->handle() => __($field->display())])
            ->all();
    }

    public function routes(Router $router): void
    {
        $router->get('merge-fields', MailchimpMergeFieldsController::class)->name('merge-fields');
        $router->patch('/', [MailchimpConnectionController::class, 'update'])->name('update');
    }
}
