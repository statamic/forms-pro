<?php

namespace Tests\FormConnections\Slack;

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

    #[Test]
    public function it_returns_a_job_per_slack_config()
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
    public function it_returns_no_jobs_when_the_form_has_no_slack_configs()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty((new Slack)->finalized($form->makeSubmission()));
    }

    #[Test]
    public function it_skips_disabled_configs()
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
    public function it_filters_configs_using_conditions($conditions, $value, $shouldDispatch)
    {
        $form = tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'how_did_you_hear', 'field' => ['type' => 'text']],
            ],
        ])->connections(['slack' => [
            ['id' => 'abc', 'channel' => 'C0123ABCDEF', 'conditions' => $conditions],
        ]]))->save();

        $submission = $form->makeSubmission()->data(['how_did_you_hear' => $value]);

        $this->assertCount($shouldDispatch ? 1 : 0, (new Slack)->finalized($submission));
    }

    public static function conditionsProvider()
    {
        $conditions = [['field' => 'how_did_you_hear', 'operator' => 'equals', 'value' => 'friend', 'join' => 'and']];

        return [
            'no conditions' => [[], 'google', true],
            'matching conditions' => [$conditions, 'friend', true],
            'non-matching conditions' => [$conditions, 'google', false],
        ];
    }

    #[Test]
    public function it_counts_configs()
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
        config(['forms-pro.connections.slack.token' => 'xoxb-token']);

        $form = tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Full Name']],
                ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ],
        ]))->save();

        $component = (new Slack)->render($form)->toArray();

        $this->assertEquals('slack-connection', $component['name']);
        $this->assertEquals(cp_route('forms.connect.slack.update', 'test'), $component['props']['action']);
        $this->assertEquals(cp_route('forms.connect.slack.channels', 'test'), $component['props']['channelsUrl']);
        $this->assertEquals(cp_route('forms.connect.slack.test', 'test'), $component['props']['testUrl']);
        $this->assertTrue($component['props']['configured']);
        $this->assertEquals([['handle' => 'name', 'display' => 'Full Name']], $component['props']['fields']);
    }

    #[Test]
    public function it_reports_when_no_token_has_been_configured()
    {
        config(['forms-pro.connections.slack.token' => null]);

        $form = tap(Form::make('test'))->save();

        $this->assertFalse((new Slack)->render($form)->toArray()['props']['configured']);
    }
}
