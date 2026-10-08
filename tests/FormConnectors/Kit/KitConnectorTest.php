<?php

namespace Tests\FormConnectors\Kit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Kit;
use Statamic\FormsPro\FormConnectors\Kit\SubscribeToKit;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class KitConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;

    private const int FORM = 9785233;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.kit.api_key' => 'kit_apikey']);
    }

    #[Test]
    public function it_returns_a_job_when_an_email_field_is_configured()
    {
        $form = $this->form([['id' => 'abc', 'email_field' => 'email']]);

        $jobs = $this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToKit::class, $jobs);
        $this->assertEquals('email', $jobs[0]->config['email_field']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_kit()
    {
        $this->assertEmpty($this->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_email_field_is_missing()
    {
        $form = $this->form([['id' => 'abc', 'form' => self::FORM]]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $form = $this->form([['id' => 'abc', 'email_field' => 'email']]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    #[DataProvider('consentConditionProvider')]
    public function it_only_subscribes_when_the_consent_condition_passes(string $field, string $operator, string $condition, $value, bool $shouldSubscribe)
    {
        $form = $this->form([[
            'id' => 'abc',
            'email_field' => 'email',
            'conditions' => [['field' => $field, 'operator' => $operator, 'value' => $condition]],
        ]]);

        $jobs = $this->finalized($form->makeSubmission()->data([
            'email' => 'jack@example.com',
            $field => $value,
        ]));

        $this->assertCount($shouldSubscribe ? 1 : 0, $jobs);
    }

    public static function consentConditionProvider(): array
    {
        return [
            'checked toggle' => ['consent', 'equals', 'true', true, true],
            'unchecked toggle' => ['consent', 'equals', 'true', false, false],
            'missing toggle' => ['consent', 'equals', 'true', null, false],
            'checked checkbox' => ['newsletter', 'contains', 'yes', ['yes'], true],
            'unchecked checkbox' => ['newsletter', 'contains', 'yes', [], false],
            'missing checkbox' => ['newsletter', 'contains', 'yes', null, false],
            'yes radio' => ['subscribe', 'equals', 'yes', 'yes', true],
            'no radio' => ['subscribe', 'equals', 'yes', 'no', false],
        ];
    }

    #[Test]
    public function it_renders_the_connection_with_the_forms_tags_and_custom_fields()
    {
        $this->fakeAccount();

        $props = (new Kit)->forForm($this->form([['id' => 'abc', 'form' => self::FORM, 'email_field' => 'email']]))->render()->toArray()['props'];

        $this->assertEquals(['blueprint', 'meta', 'defaults', 'isConfigured', 'apiError'], array_keys($props));
        $this->assertEquals(['abc'], array_keys($props['meta']));
        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);

        $this->assertEquals([
            ['value' => (string) self::FORM, 'label' => 'Newsletter'],
            ['value' => '9776140', 'label' => 'Newsletter site'],
        ], $props['defaults']['meta']['form']['options']);

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['defaults']['meta']['tags']['options']);
    }

    #[Test]
    public function it_follows_pagination_when_listing_tags()
    {
        $this->fakeHttp([
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

        $props = (new Kit)->forForm($this->form())->render()->toArray()['props'];

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['defaults']['meta']['tags']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.kit.api_key' => null]);

        $this->fakeHttp();

        $props = (new Kit)->forForm($this->form())->render()->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->fakeHttp(['api.kit.com/*' => Http::response(null, 401)]);

        $props = (new Kit)->forForm($this->form())->render()->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_KIT_API_KEY', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['form']['options']);
        $this->assertEquals([], $props['defaults']['meta']['tags']['options']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_api_key()
    {
        $this->fakeAccount();

        (new Kit)->forForm($this->form())->render();

        config(['statamic.forms-pro.connections.kit.api_key' => 'kit_different']);

        (new Kit)->forForm($this->form())->render();

        Http::assertSentCount(6);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        $configs = (new Kit)->setForm($this->form())->preProcess([[
            'id' => 'abc',
            'form' => self::FORM,
            'email_field' => 'email',
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ]]);

        $this->assertCount(1, $configs);
        $config = $configs[0];

        $this->assertEquals('abc', $config['id']);
        $this->assertTrue($config['enabled']);

        $this->assertEquals(self::FORM, $config['form']);
        $this->assertEquals('email', $config['email_field']);
        $this->assertNotEmpty($config['custom_fields'][0]['_id']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([[
            'form' => self::FORM,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'tags' => [22367468, 22367469],
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ]], (new Kit)->setForm($this->form())->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make([$config], (new Kit)->setForm($this->form())->rules());

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has('0.'.$key));
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
            'id' => 'abc',
            'form' => self::FORM,
            'email_field' => 'email',
            'first_name_field' => 'name',
            'tags' => [22367468, 22367469],
            'custom_fields' => [['key' => 'last_name', 'value' => 'name']],
        ];

        $this->assertEquals([$config], (new Kit)->setForm($this->form())->process([$config]));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals([['id' => 'abc', 'email_field' => 'email']], (new Kit)->setForm($this->form())->process([[
            'id' => 'abc',
            'form' => null,
            'email_field' => 'email',
            'first_name_field' => null,
            'tags' => [],
            'custom_fields' => [],
        ]]));
    }

    private function fakeAccount(): void
    {
        $this->fakeHttp([
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

    private function form(array $connections = []): FormContract
    {
        $form = Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
                ['handle' => 'newsletter', 'field' => ['type' => 'checkboxes', 'options' => ['yes' => 'Yes']]],
                ['handle' => 'subscribe', 'field' => ['type' => 'radio', 'options' => ['yes' => 'Yes', 'no' => 'No']]],
            ],
        ]);

        if ($connections) {
            $form->connections(['kit' => $connections]);
        }

        return tap($form)->save();
    }

    private function finalized(Submission $submission): array
    {
        return (new Kit)->forForm($submission->form())->finalized($submission);
    }
}
