<?php

namespace Tests\FormConnections\Slack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Slack;
use Statamic\FormsPro\FormConnections\Slack\SendSlackMessage;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class SlackConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.slack.token' => 'xoxb-token']);
    }

    #[Test]
    public function it_returns_a_job_per_slack_notification()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF'],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ]]))->save();

        $jobs = (new Slack)->finalized($form->makeSubmission());

        $this->assertCount(2, $jobs);
        $this->assertContainsOnlyInstancesOf(SendSlackMessage::class, $jobs);
        $this->assertEquals('C0123ABCDEF', $jobs[0]->config['channel']);
        $this->assertEquals('C0456DEFGHI', $jobs[1]->config['channel']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_has_no_slack_notifications()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty((new Slack)->finalized($form->makeSubmission()));
    }

    #[Test]
    public function it_skips_disabled_notifications()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF', 'enabled' => false],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ]]))->save();

        $jobs = (new Slack)->finalized($form->makeSubmission());

        $this->assertCount(1, $jobs);
        $this->assertEquals('C0456DEFGHI', $jobs[0]->config['channel']);
    }

    #[Test]
    #[DataProvider('conditionsProvider')]
    public function it_filters_notifications_using_conditions(array $conditions, string $value, bool $shouldDispatch)
    {
        $form = tap(Form::make('test')
            ->formFields([
                'fields' => [
                    ['handle' => 'how_did_you_hear', 'field' => ['type' => 'text']],
                ],
            ])
            ->connections([
                'slack' => [
                    ['id' => 'abc', 'channel' => 'C0123ABCDEF', 'conditions' => $conditions],
                ],
            ]))
            ->save();

        $submission = $form->makeSubmission()->data(['how_did_you_hear' => $value]);

        $this->assertCount($shouldDispatch ? 1 : 0, (new Slack)->finalized($submission));
    }

    public static function conditionsProvider(): array
    {
        $conditions = [['field' => 'how_did_you_hear', 'operator' => 'equals', 'value' => 'friend', 'join' => 'and']];

        return [
            'no conditions' => [[], 'google', true],
            'matching conditions' => [$conditions, 'friend', true],
            'non-matching conditions' => [$conditions, 'google', false],
        ];
    }

    #[Test]
    public function it_counts_notifications()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF'],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ]]))->save();

        $this->assertEquals(2, (new Slack)->count($form));
    }

    #[Test]
    public function it_renders_the_vue_component()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channels' => []])]);

        $form = tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Full Name']],
                ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ],
        ]))->save();

        $component = (new Slack)->render($form)->toArray();

        $this->assertEquals('slack-connection', $component['name']);
        $this->assertEquals(cp_route('forms.connect.slack.update', 'test'), $component['props']['action']);
        $this->assertTrue($component['props']['isConfigured']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.slack.token' => null]);

        Http::fake();

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => false, 'error' => 'invalid_auth'])]);

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertEquals('Error from Slack: [invalid_auth].', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['channel']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_slack_is_unavailable()
    {
        Http::fake(['slack.com/*' => Http::response(null, 503)]);

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertEquals('Slack responded with a [503] status code.', $props['apiError']);
    }

    #[Test]
    public function it_renders_an_api_error_when_slack_cant_be_reached()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }
}
