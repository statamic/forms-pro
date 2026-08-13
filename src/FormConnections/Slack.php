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
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Slack\SendSlackMessage;
use Statamic\FormsPro\FormConnections\Slack\SlackClient;
use Statamic\FormsPro\FormConnections\Slack\SlackException;
use Statamic\FormsPro\Http\Controllers\CP\SlackConnectionController;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Slack extends Connection
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.slack_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/slack.svg');
    }

    public function count(Form $form): ?int
    {
        return count($form->connections()->get('slack', []));
    }

    public function finalized(Submission $submission): object|array
    {
        return collect($submission->form()->connections()->get('slack'))
            ->filter(fn (array $config) => ConnectionLogic::passes($config, $submission))
            ->map(fn (array $config) => new SendSlackMessage($submission, $submission->site(), $config))
            ->values()
            ->all();
    }

    public function render(Form $form): VueComponent
    {
        $isConfigured = (bool) config('forms-pro.connections.slack.token');

        try {
            $channels = $isConfigured ? $this->channels() : [];
        } catch (ConnectionException|SlackException $e) {
            $channels = [];
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $channels);
        $fields = $blueprint->fields()->preProcess();

        return VueComponent::render('slack-connection', [
            'action' => cp_route('forms.connect.slack.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'notifications' => collect($form->connections()->get('slack'))
                ->mapWithKeys(function (array $config) use ($fields): array {
                    $fields = $fields->addValues($config)->preProcess();

                    return [$config['id'] => [
                        'values' => $fields->values()->all(),
                        'meta' => $fields->meta()->all(),
                    ]];
                })
                ->all(),
            'defaults' => [
                'values' => $fields->values()->all(),
                'meta' => $fields->meta()->all(),
            ],
            'isConfigured' => $isConfigured,
            'apiError' => $apiError ?? null,
        ]);
    }

    private function channels(): array
    {
        $slack = app(SlackClient::class);

        return Cache::remember(
            "forms-pro.slack.channels.{$slack->fingerprint()}",
            now()->addMinutes(5),
            fn () => $slack->channels()->all()
        );
    }

    public static function blueprint(Form $form, array $channels = []): \Statamic\Fields\Blueprint
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'channel',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Channel'),
                                        'instructions' => __('forms-pro::messages.slack_connection_channel_instructions'),
                                        'options' => collect($channels)->mapWithKeys(fn (array $channel) => [$channel['id'] => '#'.$channel['name']])->all(),
                                        'validate' => ['required'],
                                        'searchable' => true,
                                    ],
                                ],
                                // TODO: Replace with autocomplete editor once its merged (https://github.com/statamic/cms/pull/15055)
                                [
                                    'handle' => 'heading',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Heading'),
                                        'validate' => ['max:2000'],
                                    ],
                                ],
                                [
                                    'handle' => 'link_to_submission',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Link to Submission'),
                                        'instructions' => __('forms-pro::messages.slack_connection_link_to_submission_instructions'),
                                        'default' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'include_fields',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Include Submitted Fields'),
                                        'instructions' => __('forms-pro::messages.slack_connection_include_fields_instructions'),
                                        'default' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'fields',
                                    'field' => [
                                        'type' => 'checkboxes',
                                        'display' => __('Fields to Include'),
                                        'instructions' => __('forms-pro::messages.slack_connection_fields_instructions'),
                                        'options' => $form->formFields()->fields()
                                            ->reject(fn ($field) => array_intersect($field->fieldtype()->categories(), ['information', 'structure']))
                                            ->mapWithKeys(fn ($field) => [$field->handle() => __($field->display())])
                                            ->all(),
                                        'if' => ['include_fields' => true],
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
        $router->patch('/', [SlackConnectionController::class, 'update'])->name('update');
    }
}
