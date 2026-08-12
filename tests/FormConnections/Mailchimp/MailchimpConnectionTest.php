<?php

namespace Tests\FormConnections\Mailchimp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Mailchimp;
use Statamic\FormsPro\FormConnections\Mailchimp\SubscribeToMailchimp;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class MailchimpConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const AUDIENCE = 'a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.mailchimp.api_key' => 'mailchimp-key-us14']);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_audience()
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $jobs = (new Mailchimp)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToMailchimp::class, $jobs);
        $this->assertEquals(self::AUDIENCE, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_mailchimp()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty((new Mailchimp)->finalized($form->makeSubmission()));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $form = $this->form($config);

        $this->assertEmpty((new Mailchimp)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    public static function incompleteConfigProvider(): array
    {
        return [
            'no audience' => [['email_field' => 'email']],
            'no email field' => [['list' => self::AUDIENCE]],
        ];
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $this->assertEmpty((new Mailchimp)->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    #[DataProvider('consentProvider')]
    public function it_only_subscribes_when_consent_has_been_given($value, bool $shouldSubscribe)
    {
        $form = $this->form([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'consent_field' => 'consent',
        ]);

        $jobs = (new Mailchimp)->finalized($form->makeSubmission()->data([
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
            'checked checkbox' => [['newsletter'], true],
            'unchecked checkbox' => [[], false],
            'yes' => ['yes', true],
            'no' => ['no', false],
        ];
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_audiences()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $props = (new Mailchimp)->render($this->form(['list' => self::AUDIENCE, 'email_field' => 'email']))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals(self::AUDIENCE, $props['values']['list']);
        $this->assertEquals([['value' => self::AUDIENCE, 'label' => 'Newsletter']], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.mailchimp.api_key' => null]);

        Http::fake();

        $props = (new Mailchimp)->render($this->form([]))->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(null, 401)]);

        $props = (new Mailchimp)->render($this->form([]))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_MAILCHIMP_API_KEY', $props['apiError']);
        $this->assertEquals([], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_mailchimp_cant_be_reached()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $props = (new Mailchimp)->render($this->form([]))->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }

    #[Test]
    public function it_preselects_the_audience_when_theres_only_one()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $props = (new Mailchimp)->render($this->form([]))->toArray()['props'];

        $this->assertEquals(self::AUDIENCE, $props['values']['list']);
    }

    #[Test]
    public function it_doesnt_preselect_an_audience_when_there_are_several()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
            ['id' => 'f6e5d4c3b2', 'name' => 'Customers'],
        ]])]);

        $props = (new Mailchimp)->render($this->form([]))->toArray()['props'];

        $this->assertNull($props['values']['list']);
    }

    private function form(array $config): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ])->connections(['mailchimp' => $config]))->save();
    }
}
