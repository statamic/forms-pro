<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\Forms\Connectors\ConnectionLogic;
use Statamic\FormsPro\FormConnectors\Slack\Rules\SlackChannel;
use Statamic\FormsPro\FormConnectors\Slack\SendSlackMessage;
use Statamic\FormsPro\FormConnectors\Slack\SlackClient;
use Statamic\FormsPro\FormConnectors\Slack\SlackException;
use Statamic\Support\Arr;
use Statamic\Support\Str;
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

    public function count(Form $form): ?int
    {
        return count($form->connections()->get('slack', []));
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.slack.token');
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
                ->mapWithKeys(fn (array $config): array => [
                    $config['id'] => $fields->addValues($config)->preProcess()->meta()->all(),
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

    public function preProcess(array $config, Form $form): array
    {
        $fields = static::blueprint($form)->fields();

        return collect($config)
            ->map(fn (array $config): array => [
                'id' => $config['id'],
                'enabled' => Arr::get($config, 'enabled') !== false,
                'conditions' => ConnectionLogic::preProcess(Arr::get($config, 'conditions') ?? []),
                ...$fields->addValues($config)->preProcess()->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function rules(Form $form): array
    {
        return [
            '*' => ['array'],
            '*.channel' => ['required', new SlackChannel],
            '*.heading' => ['nullable', 'string', 'max:2000'],
            '*.enabled' => ['nullable', 'boolean'],
            '*.conditions' => ['nullable', 'array'],
            '*.conditions.*' => ['array'],
        ];
    }

    public function process(array $data, Form $form): array
    {
        $fields = static::blueprint($form)->fields();

        return collect($data)
            ->map(function (array $config) use ($fields): array {
                $config = Arr::removeNullValues($config);

                $values = $fields
                    ->addValues($config)
                    ->process()
                    ->values()
                    ->all();

                return Arr::removeNullValues([
                    'id' => Arr::get($config, 'id') ?? Str::random(8),
                    ...$values,
                    'enabled' => Arr::get($config, 'enabled') === false ? false : null,
                    'include_fields' => Arr::get($values, 'include_fields') === false ? false : null,
                    'link_to_submission' => Arr::get($values, 'link_to_submission') === false ? false : null,
                    'conditions' => ConnectionLogic::process(Arr::get($config, 'conditions') ?? []),
                ]);
            })
            ->values()
            ->all();
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
