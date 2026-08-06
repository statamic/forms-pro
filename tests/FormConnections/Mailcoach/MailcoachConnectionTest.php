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

    private const LIST = '9dcbb6dc-1b47-4d2e-9d3f-8dbf1a92a5f1';

    #[Test]
    public function it_returns_a_job_for_the_configured_list()
    {
        $form = $this->form(['list' => self::LIST, 'email_field' => 'email']);

        $jobs = new Mailcoach()->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToMailcoach::class, $jobs);
        $this->assertEquals(self::LIST, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_mailcoach()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty(new Mailcoach()->finalized($form->makeSubmission()));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $form = $this->form($config);

        $this->assertEmpty(new Mailcoach()->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
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

        $this->assertEmpty(new Mailcoach()->finalized($form->makeSubmission()->data(['email' => ''])));
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

        $jobs = new Mailcoach()->finalized($form->makeSubmission()->data([
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
    public function it_counts_the_connection_once_configured()
    {
        $this->assertEquals(0, new Mailcoach()->count(tap(Form::make('test'))->save()));
        $this->assertEquals(1, new Mailcoach()->count($this->form(['list' => self::LIST])));
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_lists()
    {
        $this->configureMailcoach();

        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter', 'requires_confirmation' => true],
        ]])]);

        $props = new Mailcoach()->render($this->form(['list' => self::LIST, 'email_field' => 'email']))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals(self::LIST, $props['values']['list']);
        $this->assertEquals([['value' => self::LIST, 'label' => 'Newsletter']], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_mailcoach_hasnt_been_configured()
    {
        Http::fake();

        $props = new Mailcoach()->render($this->form([]))->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->configureMailcoach();

        Http::fake(['acme.mailcoach.app/*' => Http::response(null, 401)]);

        $props = new Mailcoach()->render($this->form([]))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_MAILCOACH_TOKEN', $props['apiError']);
        $this->assertEquals([], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_preselects_the_list_when_theres_only_one()
    {
        $this->configureMailcoach();

        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
        ]])]);

        $props = new Mailcoach()->render($this->form([]))->toArray()['props'];

        $this->assertEquals(self::LIST, $props['values']['list']);
    }

    #[Test]
    public function it_doesnt_preselect_a_list_when_there_are_several()
    {
        $this->configureMailcoach();

        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
            ['uuid' => '2f0b3a1c-9d4e-4a6b-8c1d-5e7f9a0b2c3d', 'name' => 'Customers'],
        ]])]);

        $props = new Mailcoach()->render($this->form([]))->toArray()['props'];

        $this->assertNull($props['values']['list']);
    }

    #[Test]
    #[DataProvider('fieldOptionsProvider')]
    public function it_only_offers_fields_that_suit_each_setting(string $handle, array $expected)
    {
        $form = tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'email']],
                ['handle' => 'full_name', 'field' => ['type' => 'name']],
                ['handle' => 'message', 'field' => ['type' => 'long_answer']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes']],
                ['handle' => 'cv', 'field' => ['type' => 'upload']],
                ['handle' => 'rating', 'field' => ['type' => 'star_rating']],
                ['handle' => 'intro', 'field' => ['type' => 'heading']],
                ['handle' => 'third_party', 'field' => ['type' => 'text']],
            ],
        ]))->save();

        $options = Mailcoach::blueprint($form)->fields()->get($handle)->get('options');

        $this->assertEquals($expected, array_keys($options));
    }

    public static function fieldOptionsProvider(): array
    {
        // "third_party" resolves to the Fallback fieldtype, which declares no categories. The form
        // builder buckets those as "other", so they're offered everywhere rather than hidden.
        $text = ['email', 'full_name', 'message', 'third_party'];

        return [
            'email' => ['email_field', $text],
            'first name' => ['first_name_field', $text],
            'last name' => ['last_name_field', $text],
            'consent' => ['consent_field', ['consent', 'interests', 'third_party']],
        ];
    }

    private function configureMailcoach(): void
    {
        config([
            'forms-pro.connections.mailcoach.api_url' => 'https://acme.mailcoach.app',
            'forms-pro.connections.mailcoach.token' => 'mailcoach-token',
        ]);
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
