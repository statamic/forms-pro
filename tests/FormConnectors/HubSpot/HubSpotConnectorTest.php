<?php

namespace Tests\FormConnectors\HubSpot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\HubSpot;
use Statamic\FormsPro\FormConnectors\HubSpot\SubmitToHubSpot;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class HubSpotConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;

    private const string FORM = '2cc5419c-d732-4a4a-a82d-c0bfbcf948a5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.hubspot.access_token' => 'pat-eu1-token']);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_form()
    {
        $jobs = $this->finalized($this->submission(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(SubmitToHubSpot::class, $jobs[0]);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_hubspot()
    {
        $form = tap(Form::make('contact_us')->formFields(['fields' => [
            ['handle' => 'email', 'field' => ['type' => 'text']],
        ]]))->save();

        $this->assertEmpty($this->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $this->assertEmpty($this->finalized($this->submission(['email' => 'jack@example.com'], $config)));
    }

    public static function incompleteConfigProvider(): array
    {
        return [
            'no form' => [['form' => null]],
            'no email field' => [['email_field' => null]],
        ];
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $this->assertEmpty($this->finalized($this->submission(['email' => null])));
    }

    #[Test]
    public function it_captures_the_tracking_context_from_the_request()
    {
        $hutk = str_repeat('a', 32);
        $this->runInConsole(false);

        $this->call('POST', '/!/forms/contact_us', server: [
            'HTTP_REFERER' => 'https://example.com/contact',
            'REMOTE_ADDR' => '1.2.3.4',
        ], cookies: ['hubspotutk' => $hutk]);

        $jobs = $this->finalized($this->submission(['email' => 'jack@example.com']));

        $this->assertEquals([
            'hutk' => $hutk,
            'ipAddress' => '1.2.3.4',
            'pageUri' => 'https://example.com/contact',
        ], $jobs[0]->context);
    }

    #[Test]
    public function it_doesnt_capture_the_tracking_context_in_the_control_panel()
    {
        $this->runInConsole(false);

        $this->call('POST', '/cp/forms/contact_us/submissions/fake', server: [
            'HTTP_REFERER' => 'https://example.com/cp/forms/contact_us',
            'REMOTE_ADDR' => '1.2.3.4',
        ], cookies: ['hubspotutk' => str_repeat('a', 32)]);

        $this->assertSame([], $this->finalized($this->submission(['email' => 'jack@example.com']))[0]->context);
    }

    #[Test]
    public function it_doesnt_capture_the_tracking_context_in_the_console()
    {
        $this->call('POST', '/!/forms/contact_us', server: [
            'HTTP_REFERER' => 'https://example.com/contact',
            'REMOTE_ADDR' => '1.2.3.4',
        ], cookies: ['hubspotutk' => str_repeat('a', 32)]);

        $this->assertSame([], $this->finalized($this->submission(['email' => 'jack@example.com']))[0]->context);
    }

    #[Test]
    public function it_ignores_tracking_values_hubspot_would_reject()
    {
        $this->runInConsole(false);

        $this->call('POST', '/!/forms/contact_us', server: [
            'HTTP_REFERER' => 'not a url',
        ], cookies: ['hubspotutk' => 'not-a-tracking-token']);

        $context = $this->finalized($this->submission(['email' => 'jack@example.com']))[0]->context;

        $this->assertArrayNotHasKey('hutk', $context);
        $this->assertArrayNotHasKey('pageUri', $context);
    }

    #[Test]
    public function it_renders_the_vue_component()
    {
        $this->fakeAccount();

        $component = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertEquals('hubspot-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'defaults', 'hubspotForms', 'isConfigured', 'apiError'], array_keys($component['props']));
        $this->assertTrue($component['props']['isConfigured']);
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_forms()
    {
        $this->fakeAccount();

        $rendered = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertEquals(
            [self::FORM => 'Contact Us'],
            $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['options'],
        );
    }

    #[Test]
    public function it_follows_pagination_when_listing_forms()
    {
        $this->fakeHttp([
            'api.hubapi.com/marketing/v3/forms*after=page-two*' => Http::response(['results' => [
                array_merge($this->definition(), ['id' => 'second-form', 'name' => 'Careers']),
            ]]),
            'api.hubapi.com/marketing/v3/forms*' => Http::response([
                'results' => [$this->definition()],
                'paging' => ['next' => ['after' => 'page-two']],
            ]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['subscriptionDefinitions' => []]),
        ]);

        $rendered = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertEquals(
            ['second-form' => 'Careers', self::FORM => 'Contact Us'],
            $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['options'],
        );
    }

    #[Test]
    public function it_preselects_the_form_when_theres_only_one()
    {
        $this->fakeAccount();

        $rendered = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertEquals(self::FORM, $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['default']);
    }

    #[Test]
    public function it_passes_the_summarised_forms_to_the_component()
    {
        $this->fakeAccount();

        $forms = (new HubSpot)->forForm($this->form())->render()->toArray()['props']['hubspotForms'];

        $this->assertEquals('Contact Us', $forms[self::FORM]['name']);
        $this->assertFalse($forms[self::FORM]['captcha']);
        $this->assertTrue($forms[self::FORM]['has_email']);
        $this->assertEquals([
            ['name' => 'firstname', 'label' => 'First Name', 'object_type_id' => '0-1', 'required' => false],
            ['name' => 'company', 'label' => 'Company', 'object_type_id' => '0-1', 'required' => true],
        ], $forms[self::FORM]['properties']);
    }

    #[Test]
    public function it_excludes_the_email_property_from_the_summary()
    {
        $this->fakeAccount();

        $properties = (new HubSpot)->forForm($this->form())->render()->toArray()['props']['hubspotForms'][self::FORM]['properties'];

        $this->assertNotContains('email', array_column($properties, 'name'));
    }

    /**
     * @see https://github.com/statamic/forms-pro/issues/38
     */
    #[Test]
    public function it_excludes_hidden_properties_from_the_summary()
    {
        $definition = $this->definition();
        $definition['fieldGroups'][0]['fields'][] = [
            'objectTypeId' => '0-1', 'name' => 'lead_source', 'required' => false, 'hidden' => true,
        ];

        $this->fakeHttp([
            'api.hubapi.com/marketing/v3/forms*' => Http::response(['results' => [$definition]]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['subscriptionDefinitions' => []]),
        ]);

        $properties = (new HubSpot)->forForm($this->form())->render()->toArray()['props']['hubspotForms'][self::FORM]['properties'];

        $this->assertNotContains('lead_source', array_column($properties, 'name'));
    }

    #[Test]
    public function it_still_renders_the_forms_when_the_subscription_lookup_fails()
    {
        $this->fakeHttp([
            'api.hubapi.com/marketing/v3/forms*' => Http::response(['results' => [$this->definition()]]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['category' => 'MISSING_SCOPE'], 403),
        ]);

        $rendered = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertNull($rendered['props']['apiError']);
        $this->assertCount(1, $rendered['props']['hubspotForms']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.hubspot.access_token' => null]);

        $this->fakeHttp();

        $props = (new HubSpot)->forForm($this->form())->render()->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->fakeHttp(fn () => throw new ConnectionException('Could not resolve host.'));

        $rendered = (new HubSpot)->forForm($this->form())->render()->toArray();

        $this->assertEquals('Could not resolve host.', $rendered['props']['apiError']);
        $this->assertEmpty($rendered['props']['hubspotForms']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_access_token()
    {
        $this->fakeAccount();

        (new HubSpot)->forForm($this->form())->render();

        config(['statamic.forms-pro.connections.hubspot.access_token' => 'pat-eu1-different']);

        (new HubSpot)->forForm($this->form())->render();

        Http::assertSentCount(4);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        $this->fakeAccount();

        $configs = (new HubSpot)->setForm($this->form())->preProcess([['id' => 'abc', 'form' => self::FORM, 'email_field' => 'email']]);

        $this->assertCount(1, $configs);
        $config = $configs[0];

        $this->assertEquals('abc', $config['id']);
        $this->assertTrue($config['enabled']);
        $this->assertEquals(self::FORM, $config['form']);
        $this->assertEquals('email', $config['email_field']);
    }

    #[Test]
    public function it_preselects_the_form_when_pre_processing_an_empty_config()
    {
        $this->fakeAccount();

        $this->assertEquals(self::FORM, (new HubSpot)->setForm($this->form())->preProcess([['id' => 'abc']])[0]['form']);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $validator = Validator::make([[
            'form' => self::FORM,
            'email_field' => 'email',
            'consent_to_process_field' => 'marketing',
            'legitimate_interest_subscription' => 3314676590,
            'properties' => [['key' => 'firstname', 'value' => 'first_name']],
            'communications' => [['subscription' => 3314676590, 'field' => 'marketing']],
        ]], (new HubSpot)->setForm($this->form())->rules());

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $validator = Validator::make([$config], (new HubSpot)->setForm($this->form())->rules());

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has('0.'.$key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'missing form' => [['email_field' => 'email'], ['form']],
            'form that isnt a uuid' => [['form' => 'contact-us', 'email_field' => 'email'], ['form']],
            'missing email field' => [['form' => self::FORM], ['email_field']],
            'unknown email field' => [['form' => self::FORM, 'email_field' => 'nope'], ['email_field']],
            'email as a property' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'email', 'value' => 'first_name']]],
                ['properties.0.key'],
            ],
            'the same property twice' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [
                    ['key' => 'firstname', 'value' => 'first_name'],
                    ['key' => 'firstname', 'value' => 'email'],
                ]],
                ['properties.0.key', 'properties.1.key'],
            ],
            'property with an unknown form field' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'firstname', 'value' => 'nope']]],
                ['properties.0.value'],
            ],
            'property without a value' => [
                ['form' => self::FORM, 'email_field' => 'email', 'properties' => [['key' => 'firstname']]],
                ['properties.0.value'],
            ],
            'subscription that isnt an id' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [['subscription' => 'marketing', 'field' => 'marketing']]],
                ['communications.0.subscription'],
            ],
            'the same subscription twice' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [
                    ['subscription' => 3314676590, 'field' => 'marketing'],
                    ['subscription' => 3314676590, 'field' => 'first_name'],
                ]],
                ['communications.0.subscription', 'communications.1.subscription'],
            ],
            'communication with an unknown form field' => [
                ['form' => self::FORM, 'email_field' => 'email', 'communications' => [['subscription' => 3314676590, 'field' => 'nope']]],
                ['communications.0.field'],
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
            'consent_to_process_field' => 'marketing',
            'legitimate_interest_subscription' => 3314676590,
            'properties' => [['key' => 'firstname', 'value' => 'first_name']],
            'communications' => [['subscription' => 3314676590, 'field' => 'marketing']],
        ];

        $this->assertEquals([$config], (new HubSpot)->setForm($this->form())->process([$config]));
    }

    #[Test]
    public function it_doesnt_persist_empty_values()
    {
        $this->assertEquals([[
            'id' => 'abc',
            'form' => self::FORM,
            'email_field' => 'email',
        ]], (new HubSpot)->setForm($this->form())->process([[
            'id' => 'abc',
            'form' => self::FORM,
            'email_field' => 'email',
            'consent_to_process_field' => null,
            'legitimate_interest_subscription' => null,
            'properties' => [],
            'communications' => [],
        ]]));
    }

    private function definition(): array
    {
        return [
            'id' => self::FORM,
            'name' => 'Contact Us',
            'archived' => false,
            'configuration' => ['recaptchaEnabled' => false],
            'fieldGroups' => [['fields' => [
                ['objectTypeId' => '0-1', 'name' => 'email', 'label' => 'Email', 'required' => true, 'hidden' => false],
                ['objectTypeId' => '0-1', 'name' => 'firstname', 'label' => 'First Name', 'required' => false, 'hidden' => false],
                ['objectTypeId' => '0-1', 'name' => 'company', 'label' => 'Company', 'required' => true, 'hidden' => false],
            ]]],
            'legalConsentOptions' => ['type' => 'none'],
        ];
    }

    private function fakeAccount(): void
    {
        $this->fakeHttp([
            'api.hubapi.com/marketing/v3/forms*' => Http::response(['results' => [$this->definition()]]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['subscriptionDefinitions' => [
                ['id' => '3314676590', 'name' => 'Marketing Information'],
            ]]),
        ]);
    }

    private function form(): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'first_name', 'field' => ['type' => 'text']],
                ['handle' => 'marketing', 'field' => ['type' => 'toggle']],
            ],
        ]))->save();
    }

    private function submission(array $data, array $config = []): SubmissionContract
    {
        $form = $this->form();
        $config = array_merge(['id' => 'abc', 'form' => self::FORM, 'email_field' => 'email'], $config);

        $form->connections($form->connections()->put('hubspot', [$config]))->save();

        return $form->makeSubmission()->data($data);
    }

    private function finalized(SubmissionContract $submission): array
    {
        return (new HubSpot)->forForm($submission->form())->finalized($submission);
    }

    private function runInConsole(bool $value): void
    {
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $property->setValue($this->app, $value);
    }
}
