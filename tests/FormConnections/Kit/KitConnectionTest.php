<?php

namespace Tests\FormConnections\Kit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Kit;
use Statamic\FormsPro\FormConnections\Kit\SubscribeToKit;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class KitConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const int FORM = 9785233;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.kit.api_key' => 'kit_apikey']);
    }

    #[Test]
    public function it_returns_a_job_when_an_email_field_is_configured()
    {
        $form = $this->form(['email_field' => 'email']);

        $jobs = (new Kit)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToKit::class, $jobs);
        $this->assertEquals('email', $jobs[0]->config['email_field']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_kit()
    {
        $this->assertEmpty((new Kit)->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_email_field_is_missing()
    {
        $form = $this->form(['form' => self::FORM]);

        $this->assertEmpty((new Kit)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $form = $this->form(['email_field' => 'email']);

        $this->assertEmpty((new Kit)->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    #[DataProvider('consentProvider')]
    public function it_only_subscribes_when_consent_has_been_given($value, bool $shouldSubscribe)
    {
        $form = $this->form(['email_field' => 'email', 'consent_field' => 'consent']);

        $jobs = (new Kit)->finalized($form->makeSubmission()->data([
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
    public function it_renders_the_connection_with_the_forms_tags_and_custom_fields()
    {
        $this->fakeAccount();

        $props = (new Kit)->render($this->form(['form' => self::FORM, 'email_field' => 'email']))->toArray()['props'];

        $this->assertEquals(['blueprint', 'meta', 'isConfigured', 'apiError'], array_keys($props));
        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);

        $this->assertEquals([
            ['value' => (string) self::FORM, 'label' => 'Newsletter'],
            ['value' => '9776140', 'label' => 'Newsletter site'],
        ], $props['meta']['form']['options']);

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_follows_pagination_when_listing_tags()
    {
        Http::fake([
            'api.kit.com/v4/forms*' => Http::response(['forms' => []]),
            'api.kit.com/v4/custom_fields*' => Http::response(['custom_fields' => []]),
            'api.kit.com/v4/tags*after=page-two*' => Http::response(['tags' => [
                ['id' => 22367469, 'name' => 'Subscribers'],
            ]]),
            'api.kit.com/v4/tags*' => Http::response([
                'tags' => [['id' => 22367468, 'name' => 'Customers']],
                'pagination' => ['end_cursor' => 'page-two', 'has_next_page' => true],
            ]),
        ]);

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.kit.api_key' => null]);

        Http::fake();

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['api.kit.com/*' => Http::response(null, 401)]);

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_KIT_API_KEY', $props['apiError']);
        $this->assertEquals([], $props['meta']['form']['options']);
        $this->assertEquals([], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_api_key()
    {
        $this->fakeAccount();

        (new Kit)->render($this->form());

        config(['forms-pro.connections.kit.api_key' => 'kit_different']);

        (new Kit)->render($this->form());

        Http::assertSentCount(6);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        $config = (new Kit)->preProcess([
            'form' => self::FORM,
            'email_field' => 'email',
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ], $this->form());

        $this->assertEquals(self::FORM, $config['form']);
        $this->assertEquals('email', $config['email_field']);
        $this->assertNotEmpty($config['custom_fields'][0]['_id']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([
            'form' => self::FORM,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => [22367468, 22367469],
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ], (new Kit)->rules($this->form()));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make($config, (new Kit)->rules($this->form()));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'missing email field' => [[], ['email_field']],
            'unknown email field' => [['email_field' => 'nope'], ['email_field']],
            'kit form that isnt an id' => [['form' => 'newsletter', 'email_field' => 'email'], ['form']],
            'tag that isnt an id' => [['email_field' => 'email', 'tags' => ['newsletter']], ['tags.0']],
            'the same tag twice' => [
                ['email_field' => 'email', 'tags' => [22367468, 22367468]],
                ['tags.0', 'tags.1'],
            ],
            'unknown consent field' => [['email_field' => 'email', 'consent_field' => 'nope'], ['consent_field']],
            'custom field without a key' => [
                ['email_field' => 'email', 'custom_fields' => [['value' => 'name']]],
                ['custom_fields.0.key'],
            ],
            'the same custom field key twice' => [
                ['email_field' => 'email', 'custom_fields' => [
                    ['key' => 'last_name', 'value' => 'name'],
                    ['key' => 'last_name', 'value' => 'email'],
                ]],
                ['custom_fields.0.key', 'custom_fields.1.key'],
            ],
            'custom field with an unknown form field' => [
                ['email_field' => 'email', 'custom_fields' => [['key' => 'last_name', 'value' => 'nope']]],
                ['custom_fields.0.value'],
            ],
            'custom field without a value' => [
                ['email_field' => 'email', 'custom_fields' => [['key' => 'last_name']]],
                ['custom_fields.0.value'],
            ],
        ];
    }

    #[Test]
    public function it_processes_the_config()
    {
        $config = [
            'form' => self::FORM,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'consent_field' => 'consent',
            'tags' => [22367468, 22367469],
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ];

        $this->assertEquals($config, (new Kit)->process($config, $this->form()));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals(['email_field' => 'email'], (new Kit)->process([
            'form' => null,
            'email_field' => 'email',
            'first_name_field' => null,
            'consent_field' => null,
            'tags' => [],
            'custom_fields' => [],
        ], $this->form()));
    }

    private function fakeAccount(): void
    {
        Http::fake([
            'api.kit.com/v4/forms*' => Http::response(['forms' => [
                ['id' => self::FORM, 'name' => 'Newsletter'],
                ['id' => 9776140, 'name' => 'Newsletter site'],
            ]]),
            'api.kit.com/v4/tags*' => Http::response(['tags' => [
                ['id' => 22367469, 'name' => 'Subscribers'],
                ['id' => 22367468, 'name' => 'Customers'],
            ]]),
            'api.kit.com/v4/custom_fields*' => Http::response(['custom_fields' => [
                ['id' => 1335444, 'key' => 'last_name', 'label' => 'Last name'],
            ]]),
        ]);
    }

    private function form(?array $config = null): FormContract
    {
        $form = Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ]);

        if ($config !== null) {
            $form->connections(['kit' => $config]);
        }

        return tap($form)->save();
    }
}
