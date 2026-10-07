<?php

namespace Tests\FormConnectors\Mailcoach;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Mailcoach;
use Statamic\FormsPro\FormConnectors\Mailcoach\SubscribeToMailcoach;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class MailcoachConnectorTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const string LIST = '9dcbb6dc-1b47-4d2e-9d3f-8dbf1a92a5f1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.forms-pro.connections.mailcoach.api_url' => 'https://acme.mailcoach.app',
            'statamic.forms-pro.connections.mailcoach.token' => 'mailcoach-token',
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

        $this->assertEquals(['blueprint', 'meta', 'isConfigured', 'apiError'], array_keys($props));
        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals([['value' => self::LIST, 'label' => 'Newsletter']], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_follows_pagination_when_listing_lists()
    {
        Http::fake([
            'acme.mailcoach.app/*page=2*' => Http::response(['data' => [
                ['uuid' => 'list-two', 'name' => 'Product Updates'],
            ]]),
            'acme.mailcoach.app/*' => Http::response([
                'data' => [['uuid' => 'list-one', 'name' => 'Newsletter']],
                'links' => ['next' => 'https://acme.mailcoach.app/api/email-lists?page=2'],
            ]),
        ]);

        $props = (new Mailcoach)->render($this->form([]))->toArray()['props'];

        $this->assertEquals([
            ['value' => 'list-one', 'label' => 'Newsletter'],
            ['value' => 'list-two', 'label' => 'Product Updates'],
        ], $props['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.mailcoach' => null]);

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
    public function it_scopes_the_cache_key_to_the_credentials()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
        ]])]);

        (new Mailcoach)->render($this->form([]));

        config(['statamic.forms-pro.connections.mailcoach.token' => 'different-token']);

        (new Mailcoach)->render($this->form([]));

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
        ]])]);

        $config = (new Mailcoach)->preProcess([
            'list' => self::LIST,
            'email_field' => 'email',
            'attributes' => [['key' => 'full_name', 'value' => 'name']],
        ], $this->form([]));

        $this->assertEquals(self::LIST, $config['list']);
        $this->assertEquals('email', $config['email_field']);
        $this->assertNotEmpty($config['attributes'][0]['_id']);
        $this->assertFalse($config['skip_confirmation']);
    }

    #[Test]
    public function it_preselects_the_list_when_theres_only_one()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
        ]])]);

        $this->assertEquals(self::LIST, (new Mailcoach)->preProcess([], $this->form([]))['list']);
    }

    #[Test]
    public function it_doesnt_preselect_a_list_when_there_are_several()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => [
            ['uuid' => self::LIST, 'name' => 'Newsletter'],
            ['uuid' => '2f0b3a1c-9d4e-4a6b-8c1d-5e7f9a0b2c3d', 'name' => 'Customers'],
        ]])]);

        $this->assertNull((new Mailcoach)->preProcess([], $this->form([]))['list']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([
            'list' => self::LIST,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'attributes' => [['key' => 'full_name', 'value' => 'name']],
            'skip_confirmation' => true,
        ], (new Mailcoach)->rules($this->form([])));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make($config, (new Mailcoach)->rules($this->form([])));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'missing list' => [['email_field' => 'email'], ['list']],
            'list that isnt a uuid' => [['list' => 'newsletter', 'email_field' => 'email'], ['list']],
            'missing email field' => [['list' => self::LIST], ['email_field']],
            'unknown email field' => [['list' => self::LIST, 'email_field' => 'nope'], ['email_field']],
            'unknown consent field' => [['list' => self::LIST, 'email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'attribute without a key' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['value' => 'name']]],
                ['attributes.0.key'],
            ],
            'the same attribute key twice' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [
                    ['key' => 'company', 'value' => 'name'],
                    ['key' => 'company', 'value' => 'email'],
                ]],
                ['attributes.0.key', 'attributes.1.key'],
            ],
            'attribute with an unknown form field' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['key' => 'full_name', 'value' => 'nope']]],
                ['attributes.0.value'],
            ],
            'attribute without a value' => [
                ['list' => self::LIST, 'email_field' => 'email', 'attributes' => [['key' => 'full_name']]],
                ['attributes.0.value'],
            ],
        ];
    }

    #[Test]
    public function it_processes_the_config()
    {
        $config = [
            'list' => self::LIST,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'attributes' => [['key' => 'full_name', 'value' => 'name']],
            'skip_confirmation' => true,
        ];

        $this->assertEquals($config, (new Mailcoach)->process($config, $this->form([])));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals([
            'list' => self::LIST,
            'email_field' => 'email',
            'skip_confirmation' => false,
        ], (new Mailcoach)->process([
            'list' => self::LIST,
            'email_field' => 'email',
            'first_name_field' => null,
            'last_name_field' => null,
            'consent_field' => null,
            'tags' => [],
            'attributes' => [],
            'skip_confirmation' => false,
        ], $this->form([])));
    }

    private function form(array $config): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ])->connections(['mailcoach' => $config]))->save();
    }
}
