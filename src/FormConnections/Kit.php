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
use Statamic\FormsPro\FormConnections\Kit\KitClient;
use Statamic\FormsPro\FormConnections\Kit\KitException;
use Statamic\FormsPro\FormConnections\Kit\SubscribeToKit;
use Statamic\FormsPro\Http\Controllers\CP\KitConnectionController;
use Statamic\Support\Arr;
use Statamic\Support\Str;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Kit extends Connection
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.kit_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/kit.svg');
    }

    public function count(Form $form): ?int
    {
        return filled($form->connections()->get('kit')) ? 1 : 0;
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
        $account = ['forms' => [], 'tags' => [], 'custom_fields' => []];
        $isConfigured = (bool) config('forms-pro.connections.kit.api_key');

        try {
            if ($isConfigured) {
                $account = Cache::remember('forms-pro.kit.account', now()->addMinutes(5), fn (): array => [
                    'forms' => app(KitClient::class)->forms(),
                    'tags' => app(KitClient::class)->tags(),
                    'custom_fields' => app(KitClient::class)->customFields(),
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
            'action' => cp_route('forms.connect.kit.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
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
                                        'instructions' => __('forms-pro::messages.kit_connection_email_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_connection_first_name_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_connection_tags_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_connection_custom_fields_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_connection_consent_field_instructions'),
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
                                        'instructions' => __('forms-pro::messages.kit_connection_form_instructions'),
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

    public function routes(Router $router): void
    {
        $router->patch('/', [KitConnectionController::class, 'update'])->name('update');
    }
}
