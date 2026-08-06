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
use Statamic\FormsPro\FormConnections\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnections\Mailcoach\MailcoachException;
use Statamic\FormsPro\FormConnections\Mailcoach\SubscribeToMailcoach;
use Statamic\FormsPro\Http\Controllers\CP\MailcoachConnectionController;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Mailcoach extends Connection
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.mailcoach_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/mailcoach.svg');
    }

    public function count(Form $form): ?int
    {
        return filled($form->connections()->get('mailcoach')) ? 1 : 0;
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
        $isConfigured = config('forms-pro.connections.mailcoach.api_url')
            && config('forms-pro.connections.mailcoach.token');

        try {
            $lists = $isConfigured
                ? Cache::remember(
                    'forms-pro.mailcoach.email-lists',
                    now()->addMinutes(5),
                    fn () => app(MailcoachClient::class)->lists()
                )
                : [];
        } catch (ConnectionException|MailcoachException $e) {
            $lists = [];
            $apiError = $e->getMessage();
        }

        $fields = static::blueprint($form, $lists)->fields()
            ->addValues($form->connections()->get('mailcoach', []))
            ->preProcess();

        return VueComponent::render('mailcoach-connection', [
            'action' => cp_route('forms.connect.mailcoach.update', $form->handle()),
            'blueprint' => static::blueprint($form, $lists)->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    public static function blueprint(Form $form, array $lists = []): \Statamic\Fields\Blueprint
    {
        $formFields = static::formFieldOptions($form);

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
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_list_instructions'),
                                        'options' => $lists,
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'email_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Email Address'),
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_email_field_instructions'),
                                        'options' => $formFields,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'first_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('First Name'),
                                        'options' => $formFields,
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'last_name_field',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Last Name'),
                                        'options' => $formFields,
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'tags',
                                    'field' => [
                                        'type' => 'taggable',
                                        'display' => __('Tags'),
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_tags_instructions'),
                                    ],
                                ],
                                [
                                    'handle' => 'attributes',
                                    'field' => [
                                        'type' => 'grid',
                                        'mode' => 'table',
                                        'display' => __('Attributes'),
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_attributes_instructions'),
                                        'add_row' => __('Add Attribute'),
                                        'fullscreen' => false,
                                        'fields' => [
                                            [
                                                'handle' => 'key',
                                                'field' => [
                                                    'type' => 'text',
                                                    'display' => __('Mailcoach Key'),
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
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_consent_field_instructions'),
                                        'options' => $formFields,
                                        'clearable' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'skip_confirmation',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Skip Confirmation Email'),
                                        'instructions' => __('forms-pro::messages.mailcoach_connection_skip_confirmation_instructions'),
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

    private static function formFieldOptions(Form $form): array
    {
        return $form->formFields()->fields()
            ->reject(fn ($field) => array_intersect($field->fieldtype()->categories(), ['information', 'structure']))
            ->mapWithKeys(fn ($field) => [$field->handle() => __($field->display())])
            ->all();
    }

    public function routes(Router $router): void
    {
        $router->patch('/', [MailcoachConnectionController::class, 'update'])->name('update');
    }
}
