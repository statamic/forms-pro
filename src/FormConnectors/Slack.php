<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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

    private ?array $channels = null;

    private ?string $apiError = null;

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

    public function render(): VueComponent
    {
        return VueComponent::render('slack-connector', [
            ...$this->blueprintProps(),
            'isConfigured' => $this->isConfigured(),
            'apiError' => $this->apiError,
        ]);
    }

    private function channels(): array
    {
        if ($this->channels !== null || ! $this->isConfigured()) {
            return $this->channels ?? [];
        }

        try {
            $slack = app(SlackClient::class);

            return $this->channels = Cache::remember(
                "forms-pro.slack.channels.{$slack->fingerprint()}",
                now()->addMinutes(5),
                fn () => $slack->channels()->all()
            );
        } catch (ConnectionException|SlackException $e) {
            $this->apiError = $e->getMessage();

            return $this->channels = [];
        }
    }

    protected function connectionRules(): array
    {
        return [
            'channel' => [new SlackChannel],
            'heading' => ['string'],
        ];
    }

    protected function processConnection(array $connection): array
    {
        $values = parent::processConnection($connection);

        return [
            ...$values,
            'include_fields' => Arr::get($values, 'include_fields') === false ? false : null,
            'link_to_submission' => Arr::get($values, 'link_to_submission') === false ? false : null,
        ];
    }

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        $channels = $this->channels();

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
                                        'options' => $this->form()->formFields()->fields()
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
