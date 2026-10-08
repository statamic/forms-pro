<?php

namespace Tests\FormConnectors\Slack;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Slack;
use Statamic\FormsPro\FormConnectors\Slack\SendSlackMessage;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class SlackConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.slack.token' => 'xoxb-token']);
    }

    #[Test]
    public function it_returns_a_job_per_slack_notification()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF'],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ]]))->save();

        $jobs = $this->finalized($form->makeSubmission());

        $this->assertCount(2, $jobs);
        $this->assertContainsOnlyInstancesOf(SendSlackMessage::class, $jobs);
        $this->assertEquals('C0123ABCDEF', $jobs[0]->config['channel']);
        $this->assertEquals('C0456DEFGHI', $jobs[1]->config['channel']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_has_no_slack_notifications()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty($this->finalized($form->makeSubmission()));
    }

    #[Test]
    public function it_skips_disabled_notifications()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF', 'enabled' => false],
            ['id' => 'def', 'channel' => 'C0456DEFGHI'],
        ]]))->save();

        $jobs = $this->finalized($form->makeSubmission());

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

        $this->assertCount($shouldDispatch ? 1 : 0, $this->finalized($submission));
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
        $this->fakeHttp(['slack.com/*' => Http::response(['ok' => true, 'channels' => []])]);

        $form = tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Full Name']],
                ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ],
        ]))->save();

        $component = (new Slack)->render($form)->toArray();

        $this->assertEquals('slack-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'defaults', 'isConfigured', 'apiError'], array_keys($component['props']));
        $this->assertTrue($component['props']['isConfigured']);
    }

    #[Test]
    public function it_follows_pagination_when_listing_channels()
    {
        $this->fakeHttp(fn (Request $request) => match ($request['cursor']) {
            '' => Http::response([
                'ok' => true,
                'channels' => [['id' => 'C1', 'name' => 'general']],
                'response_metadata' => ['next_cursor' => 'page-two'],
            ]),
            'page-two' => Http::response([
                'ok' => true,
                'channels' => [['id' => 'C2', 'name' => 'support']],
            ]),
        });

        $form = tap(Form::make('test'))->save();

        $options = (new Slack)->render($form)->toArray()['props']['defaults']['meta']['channel']['options'];

        $this->assertEquals([
            ['value' => 'C1', 'label' => '#general'],
            ['value' => 'C2', 'label' => '#support'],
        ], $options);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.slack.token' => null]);

        $this->fakeHttp();

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->fakeHttp(['slack.com/*' => Http::response(['ok' => false, 'error' => 'invalid_auth'])]);

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertEquals('Error from Slack: [invalid_auth].', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['channel']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_slack_is_unavailable()
    {
        $this->fakeHttp(['slack.com/*' => Http::response(null, 503)]);

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertEquals('Slack responded with a [503] status code.', $props['apiError']);
    }

    #[Test]
    public function it_renders_an_api_error_when_slack_cant_be_reached()
    {
        $this->fakeHttp(fn () => throw new ConnectionException('Could not resolve host.'));

        $form = tap(Form::make('test'))->save();

        $props = (new Slack)->render($form)->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_token()
    {
        $this->fakeHttp(['slack.com/*' => Http::response(['ok' => true, 'channels' => []])]);

        $form = tap(Form::make('test'))->save();

        (new Slack)->render($form);

        config(['statamic.forms-pro.connections.slack.token' => 'xoxb-different']);

        (new Slack)->render($form);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_pre_processes_the_configs()
    {
        $form = tap(Form::make('test')->connections(['slack' => [
            [
                'id' => 'one',
                'channel' => 'C0123ABCDEF',
                'heading' => 'First',
                'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and']],
            ],
            ['id' => 'two', 'channel' => 'C0456DEFGHI', 'enabled' => false],
        ]]))->save();

        $configs = (new Slack)->preProcess($form->connections()->get('slack'), $form);

        $this->assertEquals('one', $configs[0]['id']);
        $this->assertTrue($configs[0]['enabled']);
        $this->assertEquals('C0123ABCDEF', $configs[0]['channel']);
        $this->assertEquals('First', $configs[0]['heading']);
        $this->assertNotEmpty($configs[0]['conditions'][0]['_id']);
        $this->assertEquals('two', $configs[1]['id']);
        $this->assertFalse($configs[1]['enabled']);
        $this->assertEquals([], $configs[1]['conditions']);
    }

    #[Test]
    public function it_validates_the_configs()
    {
        $form = tap(Form::make('test'))->save();

        $validator = Validator::make([
            [
                'id' => 'abc',
                'channel' => 'C0123ABCDEF',
                'heading' => 'New submission',
                'enabled' => true,
                'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and']],
            ],
        ], (new Slack)->rules($form));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs($configs, $errors)
    {
        $form = tap(Form::make('test'))->save();

        $validator = Validator::make($configs, (new Slack)->rules($form));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'config not an array' => [['nope'], ['0']],
            'config without a channel' => [[['enabled' => true]], ['0.channel']],
            'config with a channel name' => [[['channel' => '#enquiries']], ['0.channel']],
        ];
    }

    #[Test]
    public function it_processes_the_configs()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEquals([
            ['id' => 'def', 'channel' => 'C0456DEFGHI', 'enabled' => false, 'conditions' => [
                ['field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and'],
            ]],
            ['id' => 'ghi', 'channel' => 'C0789JKLMNO', 'include_fields' => false, 'link_to_submission' => false],
            ['id' => 'jkl', 'channel' => 'C0123ABCDEF'],
        ], (new Slack)->process([
            ['id' => 'def', 'channel' => 'C0456DEFGHI', 'enabled' => false, 'conditions' => [
                ['_id' => 'vue-row', 'field' => 'name', 'operator' => 'equals', 'value' => 'Bob', 'join' => 'and'],
            ]],
            ['id' => 'ghi', 'channel' => 'C0789JKLMNO', 'include_fields' => false, 'link_to_submission' => false],
            ['id' => 'jkl', 'channel' => 'C0123ABCDEF', 'enabled' => true, 'include_fields' => true, 'link_to_submission' => true],
        ], $form));
    }

    #[Test]
    public function it_doesnt_persist_the_client_row_state()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEquals([
            ['id' => 'abc', 'channel' => 'C0123ABCDEF'],
        ], (new Slack)->process([
            ['_id' => 'vue-row', 'id' => 'abc', 'channel' => 'C0123ABCDEF'],
        ], $form));
    }

    #[Test]
    public function it_generates_an_id_for_configs_that_dont_have_one()
    {
        $form = tap(Form::make('test'))->save();

        $config = (new Slack)->process([['channel' => 'C0123ABCDEF']], $form)[0];

        $this->assertNotEmpty($config['id']);
        $this->assertEquals('C0123ABCDEF', $config['channel']);
    }

    #[Test]
    public function it_removes_null_values_from_configs()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEquals([[
            'id' => 'abc',
            'channel' => 'C0123ABCDEF',
        ]], (new Slack)->process([[
            'id' => 'abc',
            'channel' => 'C0123ABCDEF',
            'heading' => null,
            'enabled' => null,
        ]], $form));
    }

    private function finalized(Submission $submission): array
    {
        return (new Slack)->setConfig($submission->form()->connections()->get(Slack::handle(), []))->finalized($submission);
    }
}
