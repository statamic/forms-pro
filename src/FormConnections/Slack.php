<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Forms\Connections\Connection;
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Slack\SendSlackMessage;
use Statamic\FormsPro\FormConnections\Slack\SlackClient;
use Statamic\FormsPro\Http\Controllers\CP\SendTestSlackMessageController;
use Statamic\FormsPro\Http\Controllers\CP\SlackChannelsController;
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
            ->reject(fn (array $config) => ($config['enabled'] ?? true) === false)
            ->filter(fn (array $config) => ConnectionLogic::passes($config, $submission))
            ->map(fn (array $config) => new SendSlackMessage($submission, $submission->site(), $config))
            ->values()
            ->all();
    }

    public function render(Form $form): VueComponent
    {
        return VueComponent::render('slack-connection', [
            'action' => cp_route('forms.connect.slack.update', $form->handle()),
            'channelsUrl' => cp_route('forms.connect.slack.channels', $form->handle()),
            'testUrl' => cp_route('forms.connect.slack.test', $form->handle()),
            'configured' => app(SlackClient::class)->configured(),
            'fields' => $this->fields($form),
        ]);
    }

    private function fields(Form $form): array
    {
        return $form->formFields()->fields()
            ->reject(fn ($field) => array_intersect($field->fieldtype()->categories(), ['information', 'structure']))
            ->map(fn ($field) => ['handle' => $field->handle(), 'display' => __($field->display())])
            ->values()
            ->all();
    }

    public function routes(Router $router): void
    {
        $router->patch('/', [SlackConnectionController::class, 'update'])->name('update');
        $router->get('/channels', SlackChannelsController::class)->name('channels');
        $router->post('/test', SendTestSlackMessageController::class)->name('test');
    }
}
