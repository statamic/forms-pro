<?php

namespace Tests\FormConnections\Mailcoach;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Mailcoach;
use Statamic\FormsPro\FormConnections\Mailcoach\SubscribeToMailcoach;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class MailcoachConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const string LIST = '9dcbb6dc-1b47-4d2e-9d3f-8dbf1a92a5f1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'forms-pro.connections.mailcoach.api_url' => 'https://acme.mailcoach.app',
            'forms-pro.connections.mailcoach.token' => 'mailcoach-token',
        ]);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_list()
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email']);

        $jobs = (new Mailcoach)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToMailcoach::class, $jobs);
        $this->assertEquals(self::LIST, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_mailcoach()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty((new Mailcoach)->finalized($form->makeSubmission()));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $form = $this->form($config);

        $this->assertEmpty((new Mailcoach)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    public static function incompleteConfigProvider(): array
    {
        return [
            'no list' => [['email_field' => 'email']],
            'no email field' => [['list' => self::LIST]],
        ];
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email']);

        $this->assertEmpty((new Mailcoach)->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    #[DataProvider('consentProvider')]
    public function it_only_subscribes_when_consent_has_been_given($value, bool $shouldSubscribe)
    {
        $form = $this->form([
            'list' => self::LIST,
            'email_field' => 'email',
            'consent_field' => 'consent',
        ]);

        $jobs = (new Mailcoach)->finalized($form->makeSubmission()->data([
            'email' => 'jack@example.com',
            'consent' => $value,
        ]));

        $this->assertCount($shouldSubscribe ? 1 : 0, $jobs);
    }

    public static function consentProvider(): array
    {
        return [
            'checked toggle' => [true, true],
            'unchecked toggle' => [false, false],
            'missing value' => [null, false],
            'empty string' => ['', false],
            'checked checkbox' => [['newsletter'], true],
            'unchecked checkbox' => [[], false],
            'yes' => ['yes', true],
            'no' => ['no', false],
            'zero' => ['0', false],
            'one' => ['1', true],
        ];
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_lists()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter', 'requires_confirmation' => true],
        ]])]);

        $props = (new Mailcoach)->render($this->form(['list' => self::LIST, 'email_field' => 'email']))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals(self::LIST, $props['values']['list']);
        $this->assertEquals([['value' => self::LIST, 'label' => 'Newsletter']], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.mailcoach' => null]);

        Http::fake();

        $props = (new Mailcoach)->render($this->form([]))->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(null, 401)]);

        $props = (new Mailcoach)->render($this->form([]))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_MAILCOACH_TOKEN', $props['apiError']);
        $this->assertEquals([], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_preselects_the_list_when_theres_only_one()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
        ]])]);

        $props = (new Mailcoach)->render($this->form([]))->toArray()['props'];

        $this->assertEquals(self::LIST, $props['values']['list']);
    }

    #[Test]
    public function it_doesnt_preselect_a_list_when_there_are_several()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
            ['uuid' => '2f0b3a1c-9d4e-4a6b-8c1d-5e7f9a0b2c3d', 'name' => 'Customers'],
        ]])]);

        $props = (new Mailcoach)->render($this->form([]))->toArray()['props'];

        $this->assertNull($props['values']['list']);
    }

    private function form(array $config): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ])->connections(['mailcoach' => $config]))->save();
    }
}
