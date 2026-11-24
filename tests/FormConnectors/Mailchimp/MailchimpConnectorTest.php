<?php

namespace Tests\FormConnectors\Mailchimp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Mailchimp;
use Statamic\FormsPro\FormConnectors\Mailchimp\SubscribeToMailchimp;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class MailchimpConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;

    private const string AUDIENCE = 'a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.mailchimp.api_key' => 'mailchimp-key-us14']);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_audience()
    {
        $form = $this->form([['id' => 'abc', 'list' => self::AUDIENCE, 'email_field' => 'email']]);

        $jobs = $this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToMailchimp::class, $jobs);
        $this->assertEquals(self::AUDIENCE, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_returns_a_job_per_connection()
    {
        $form = $this->form([
            ['id' => 'abc', 'list' => self::AUDIENCE, 'email_field' => 'email'],
            ['id' => 'def', 'list' => 'f6e5d4c3b2', 'email_field' => 'email'],
        ]);

        $jobs = $this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(2, $jobs);
        $this->assertEquals(self::AUDIENCE, $jobs[0]->config['list']);
        $this->assertEquals('f6e5d4c3b2', $jobs[1]->config['list']);
    }

    #[Test]
    public function it_skips_disabled_connections()
    {
        $form = $this->form([
            ['id' => 'abc', 'list' => self::AUDIENCE, 'email_field' => 'email', 'enabled' => false],
            ['id' => 'def', 'list' => 'f6e5d4c3b2', 'email_field' => 'email'],
        ]);

        $jobs = $this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertEquals('f6e5d4c3b2', $jobs[0]->config['list']);
    }

    #[Test]
    public function it_wraps_a_legacy_single_connection_config()
    {
        $form = $this->form(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $connections = $form->connections()->get('mailchimp');

        $this->assertCount(1, $connections);
        $this->assertNotEmpty($connections[0]['id']);

        $jobs = $this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertEquals(self::AUDIENCE, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_wraps_a_legacy_single_connection_config_when_setting_it_directly()
    {
        $form = $this->form();

        $jobs = (new Mailchimp)
            ->setConnections(['list' => self::AUDIENCE, 'email_field' => 'email'])
            ->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertEquals(self::AUDIENCE, $jobs[0]->config['list']);
    }

    #[Test]
    public function it_pre_processes_a_legacy_single_connection_config_into_a_list()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => []])]);

        $configs = (new Mailchimp)->setForm($this->form())->preProcess(['list' => self::AUDIENCE, 'email_field' => 'email']);

        $this->assertCount(1, $configs);
        $this->assertNotEmpty($configs[0]['id']);
        $this->assertTrue($configs[0]['enabled']);
        $this->assertEquals(self::AUDIENCE, $configs[0]['list']);
        $this->assertEquals('email', $configs[0]['email_field']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_mailchimp()
    {
        $form = tap(Form::make('test'))->save();

        $this->assertEmpty($this->finalized($form->makeSubmission()));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $form = $this->form([['id' => 'abc', ...$config]]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
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
        $form = $this->form([['id' => 'abc', 'list' => self::AUDIENCE, 'email_field' => 'email']]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_audiences()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $component = (new Mailchimp)->forForm($this->form([['id' => 'abc', 'list' => self::AUDIENCE, 'email_field' => 'email']]))->render()->toArray();
        $props = $component['props'];

        $this->assertEquals('mailchimp-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'defaults', 'isConfigured', 'apiError'], array_keys($props));
        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals([['value' => self::AUDIENCE, 'label' => 'Newsletter']], $props['defaults']['meta']['list']['options']);
        $this->assertEquals(['abc'], array_keys($props['meta']));
        $this->assertEquals([['value' => self::AUDIENCE, 'label' => 'Newsletter']], $props['meta']['abc']['list']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.mailchimp.api_key' => null]);

        $this->fakeHttp();

        $props = (new Mailchimp)->forForm($this->form())->render()->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(null, 401)]);

        $props = (new Mailchimp)->forForm($this->form())->render()->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_MAILCHIMP_API_KEY', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['list']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_mailchimp_cant_be_reached()
    {
        $this->fakeHttp(fn () => throw new ConnectionException('Could not resolve host.'));

        $props = (new Mailchimp)->forForm($this->form())->render()->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_api_key()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        (new Mailchimp)->forForm($this->form())->render();

        config(['statamic.forms-pro.connections.mailchimp.api_key' => 'different-key-us14']);

        (new Mailchimp)->forForm($this->form())->render();

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $form = $this->form([[
            'id' => 'abc',
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'tags' => ['newsletter'],
            'skip_confirmation' => true,
        ]]);

        $configs = (new Mailchimp)->setForm($form)->preProcess($form->connections()->get('mailchimp'));

        $this->assertCount(1, $configs);
        $this->assertEquals('abc', $configs[0]['id']);
        $this->assertTrue($configs[0]['enabled']);
        $this->assertEquals([], $configs[0]['conditions']);
        $this->assertEquals(self::AUDIENCE, $configs[0]['list']);
        $this->assertEquals('email', $configs[0]['email_field']);
        $this->assertEquals(['newsletter'], $configs[0]['tags']);
        $this->assertTrue($configs[0]['skip_confirmation']);
    }

    #[Test]
    public function it_preselects_the_audience_when_theres_only_one()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
        ]])]);

        $config = (new Mailchimp)->setForm($this->form())->preProcess([['id' => 'abc']])[0];

        $this->assertEquals(self::AUDIENCE, $config['list']);
    }

    #[Test]
    public function it_doesnt_preselect_an_audience_when_there_are_several()
    {
        $this->fakeHttp(['us14.api.mailchimp.com/*' => Http::response(['lists' => [
            ['id' => self::AUDIENCE, 'name' => 'Newsletter'],
            ['id' => 'f6e5d4c3b2', 'name' => 'Customers'],
        ]])]);

        $config = (new Mailchimp)->setForm($this->form())->preProcess([['id' => 'abc']])[0];

        $this->assertNull($config['list']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([[
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ]], (new Mailchimp)->setForm($this->form())->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make([$config], (new Mailchimp)->setForm($this->form())->rules());

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has('0.'.$key));
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
        $this->assertEquals([[
            'id' => 'abc',
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ]], (new Mailchimp)->setForm($this->form())->process([[
            'id' => 'abc',
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'tags' => ['newsletter'],
            'merge_fields' => [['key' => 'COMPANY', 'value' => 'name']],
            'groups' => [['group' => '4e4b2bc6ae', 'field' => 'consent', 'option' => 'news']],
            'marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'consent']],
            'skip_confirmation' => true,
        ]]));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals([[
            'id' => 'abc',
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'skip_confirmation' => false,
        ]], (new Mailchimp)->setForm($this->form())->process([[
            'id' => 'abc',
            'list' => self::AUDIENCE,
            'email_field' => 'email',
            'first_name_field' => null,
            'last_name_field' => null,
            'tags' => [],
            'merge_fields' => [],
            'marketing_permissions' => [],
            'skip_confirmation' => false,
        ]]));
    }

    private function form(array $connections = []): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes', 'options' => ['news' => 'News']]],
            ],
        ])->connections(['mailchimp' => $connections]))->save();
    }

    private function finalized(Submission $submission): array
    {
        return (new Mailchimp)->forForm($submission->form())->finalized($submission);
    }
}
