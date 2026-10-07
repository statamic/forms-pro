<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\Slack\Rules\SlackChannel;
use Statamic\FormsPro\FormConnectors\Slack\SendSlackMessage;
use Statamic\FormsPro\FormConnectors\Slack\SlackClient;
use Statamic\FormsPro\FormConnectors\Slack\SlackException;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class Slack extends Connector
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.slack_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/slack.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.slack.token');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        return new SendSlackMessage($submission, $submission->site(), $connection);
    }

    public function render(Form $form): VueComponent
    {
        $channels = [];
        $isConfigured = $this->isConfigured();

        try {
            $channels = $isConfigured ? $this->channels() : [];
        } catch (ConnectionException|SlackException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $channels);
        $fields = $blueprint->fields()->preProcess();

        return VueComponent::render('slack-connector', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => collect($form->connections()->get('slack'))
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

    private function channels(): array
    {
        $slack = app(SlackClient::class);

        return Cache::remember(
            "forms-pro.slack.channels.{$slack->fingerprint()}",
            now()->addMinutes(5),
            fn () => $slack->channels()->all()
        );
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
        return [
            'channel' => ['required', new SlackChannel],
            'heading' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function processConnection(array $connection, Form $form): array
    {
        $values = static::blueprint($form)->fields()
            ->addValues($connection)
            ->process()
            ->values()
            ->all();

        return [
            ...$values,
            'include_fields' => Arr::get($values, 'include_fields') === false ? false : null,
            'link_to_submission' => Arr::get($values, 'link_to_submission') === false ? false : null,
        ];
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
                                        'instructions' => __('forms-pro::messages.slack_channel_instructions'),
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
                                        'instructions' => __('forms-pro::messages.slack_heading_instructions'),
                                        'validate' => ['max:2000'],
                                    ],
                                ],
                                [
                                    'handle' => 'link_to_submission',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Link to Submission'),
                                        'instructions' => __('forms-pro::messages.slack_link_to_submission_instructions'),
                                        'default' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'include_fields',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Include Submitted Fields'),
                                        'instructions' => __('forms-pro::messages.slack_include_fields_instructions'),
                                        'default' => true,
                                        'width' => 50,
                                    ],
                                ],
                                [
                                    'handle' => 'fields',
                                    'field' => [
                                        'type' => 'checkboxes',
                                        'display' => __('Fields to Include'),
                                        'instructions' => __('forms-pro::messages.slack_fields_instructions'),
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
}
