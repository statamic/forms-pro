<?php

namespace Tests\FormConnections\Mailchimp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
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

    private const string AUDIENCE = 'a1b2c3d4e5';

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

        $component = (new Mailchimp)->render($this->form(['list' => self::AUDIENCE, 'email_field' => 'email']))->toArray();
        $props = $component['props'];

        $this->assertEquals('mailchimp-connection', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'isConfigured', 'apiError'], array_keys($props));
        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
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
    public function it_scopes_the_cache_key_to_the_api_key()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        (new Mailchimp)->render($this->form([]));

        config(['forms-pro.connections.mailchimp.api_key' => 'different-key-us14']);

        (new Mailchimp)->render($this->form([]));

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $form = $this->form([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'tags' => ['newsletter'],
            'skip_confirmation' => true,
        ]);

        $config = (new Mailchimp)->preProcess($form->connections()->get('mailchimp'), $form);

        $this->assertEquals(self::AUDIENCE, $config['list']);
        $this->assertEquals('email', $config['email_field']);
        $this->assertEquals(['newsletter'], $config['tags']);
        $this->assertTrue($config['skip_confirmation']);
    }

    #[Test]
    public function it_preselects_the_audience_when_theres_only_one()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $config = (new Mailchimp)->preProcess([], $this->form([]));

        $this->assertEquals(self::AUDIENCE, $config['list']);
    }

    #[Test]
    public function it_doesnt_preselect_an_audience_when_there_are_several()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
            ['id' => 'f6e5d4c3b2', 'name' => 'Customers'],
        ]])]);

        $config = (new Mailchimp)->preProcess([], $this->form([]));

        $this->assertNull($config['list']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ], (new Mailchimp)->rules($this->form([])));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make($config, (new Mailchimp)->rules($this->form([])));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'missing audience' => [['email_field' => 'email'], ['list']],
            'audience with a path separator' => [['list' => 'a1b2c3d4e5/members', 'email_field' => 'email'], ['list']],
            'audience with a query string' => [['list' => 'a1b2c3d4e5?count=1000', 'email_field' => 'email'], ['list']],
            'missing email field' => [['list' => self::AUDIENCE], ['email_field']],
            'unknown email field' => [['list' => self::AUDIENCE, 'email_field' => 'nope'], ['email_field']],
            'unknown consent field' => [['list' => self::AUDIENCE, 'email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'merge field without a key' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'merge field without a value' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'COMPANY']]],
                ['merge_fields.0.value'],
            ],
            'lowercase merge tag' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'company', 'value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'merge field with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'COMPANY', 'value' => 'nope']]],
                ['merge_fields.0.value'],
            ],
            'the same merge tag twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [
                    ['key' => 'COMPANY', 'value' => 'name'],
                    ['key' => 'COMPANY', 'value' => 'email'],
                ]],
                ['merge_fields.0.key', 'merge_fields.1.key'],
            ],
            'merge tag handled by a dedicated field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'merge_fields' => [['key' => 'FNAME', 'value' => 'name']]],
                ['merge_fields.0.key'],
            ],
            'group without a form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae']]],
                ['groups.0.field'],
            ],
            'the same group twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [
                    ['group' => '4e4b2bc6ae', 'field' => 'consent'],
                    ['group' => '4e4b2bc6ae', 'field' => 'name'],
                ]],
                ['groups.0.group', 'groups.1.group'],
            ],
            'group without an option when the form field has some' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae', 'field' => 'interests']]],
                ['groups.0.option'],
            ],
            'group with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'groups' => [['group' => '4e4b2bc6ae', 'field' => 'nope']]],
                ['groups.0.field'],
            ],
            'marketing permission without a field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [['permission' => 'd60cef6b1f']]],
                ['marketing_permissions.0.field'],
            ],
            'marketing permission with an unknown form field' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'nope']]],
                ['marketing_permissions.0.field'],
            ],
            'the same marketing permission twice' => [
                ['list' => self::AUDIENCE, 'email_field' => 'email', 'marketing_permissions' => [
                    ['permission' => 'd60cef6b1f', 'field' => 'consent'],
                    ['permission' => 'd60cef6b1f', 'field' => 'name'],
                ]],
                ['marketing_permissions.0.permission', 'marketing_permissions.1.permission'],
            ],
        ];
    }

    #[Test]
    public function it_processes_the_config()
    {
        $this->assertEquals([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ], (new Mailchimp)->process([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ], $this->form([])));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'skip_confirmation' => false,
        ], (new Mailchimp)->process([
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => null,
            'last_name_field' => null,
            'consent_field' => null,
            'tags' => [],
            'merge_fields' => [],
            'marketing_permissions' => [],
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
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => ['news' => 'News']]],
            ],
        ])->connections(['mailchimp' => $config]))->save();
    }
}
