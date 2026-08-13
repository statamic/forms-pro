<?php

namespace Tests\FormConnections\HubSpot;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\HubSpot;
use Statamic\FormsPro\FormConnections\HubSpot\SubmitToHubSpot;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class HubSpotConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const string FORM = '2cc5419c-d732-4a4a-a82d-c0bfbcf948a5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.hubspot.access_token' => 'pat-eu1-token']);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_form()
    {
        $jobs = (new HubSpot)->finalized($this->submission(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(SubmitToHubSpot::class, $jobs[0]);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_hubspot()
    {
        $form = tap(Form::make('contact_us')->formFields(['fields' => [
            ['handle' => 'email', 'field' => ['type' => 'text']],
        ]]))->save();

        $this->assertEmpty((new HubSpot)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    #[Test]
    #[DataProvider('incompleteConfigProvider')]
    public function it_returns_no_jobs_when_the_config_is_incomplete(array $config)
    {
        $this->assertEmpty((new HubSpot)->finalized($this->submission(['email' => 'jack@example.com'], $config)));
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
        $this->assertEmpty((new HubSpot)->finalized($this->submission(['email' => null])));
    }

    #[Test]
    #[DataProvider('consentProvider')]
    public function it_only_submits_when_consent_has_been_given(mixed $value, bool $expected)
    {
        $jobs = (new HubSpot)->finalized(
            $this->submission(['email' => 'jack@example.com', 'marketing' => $value], ['consent_field' => 'marketing'])
        );

        $this->assertCount($expected ? 1 : 0, $jobs);
    }

    public static function consentProvider(): array
    {
        return [
            'true' => [true, true],
            'array' => [['news'], true],
            'string' => ['yes', true],
            'false' => [false, false],
            'zero' => ['0', false],
            'no' => ['no', false],
            'empty array' => [[], false],
            'null' => [null, false],
        ];
    }

    #[Test]
    public function it_captures_the_tracking_context_from_the_request()
    {
        $hutk = str_repeat('a', 32);

        $this->call('POST', '/!/forms/contact_us', server: [
            'HTTP_REFERER' => 'https://example.com/contact',
            'REMOTE_ADDR' => '1.2.3.4',
        ], cookies: ['hubspotutk' => $hutk]);

        $jobs = (new HubSpot)->finalized($this->submission(['email' => 'jack@example.com']));

        $this->assertEquals([
            'hutk' => $hutk,
            'ipAddress' => '1.2.3.4',
            'pageUri' => 'https://example.com/contact',
        ], $jobs[0]->context);
    }

    #[Test]
    public function it_ignores_tracking_values_hubspot_would_reject()
    {
        $this->call('POST', '/!/forms/contact_us', server: [
            'HTTP_REFERER' => 'not a url',
        ], cookies: ['hubspotutk' => 'not-a-tracking-token']);

        $context = (new HubSpot)->finalized($this->submission(['email' => 'jack@example.com']))[0]->context;

        $this->assertArrayNotHasKey('hutk', $context);
        $this->assertArrayNotHasKey('pageUri', $context);
    }

    #[Test]
    public function it_renders_the_connection_with_the_available_forms()
    {
        $this->fakeAccount();

        $rendered = (new HubSpot)->render($this->form())->toArray();

        $this->assertEquals(
            [self::FORM => 'Contact Us'],
            $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['options'],
        );
    }

    #[Test]
    public function it_follows_pagination_when_listing_forms()
    {
        Http::fake([
            'api.hubapi.com/marketing/v3/forms*after=page-two*' => Http::response(['results' => [
                array_merge($this->definition(), ['id' => 'second-form', 'name' => 'Careers']),
            ]]),
            'api.hubapi.com/marketing/v3/forms*' => Http::response([
                'results' => [$this->definition()],
                'paging' => ['next' => ['after' => 'page-two']],
            ]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['subscriptionDefinitions' => []]),
        ]);

        $rendered = (new HubSpot)->render($this->form())->toArray();

        $this->assertEquals(
            ['second-form' => 'Careers', self::FORM => 'Contact Us'],
            $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['options'],
        );
    }

    #[Test]
    public function it_preselects_the_form_when_theres_only_one()
    {
        $this->fakeAccount();

        $rendered = (new HubSpot)->render($this->form())->toArray();

        $this->assertEquals(self::FORM, $rendered['props']['blueprint']['tabs'][0]['sections'][0]['fields'][0]['default']);
    }

    #[Test]
    public function it_passes_the_summarised_forms_to_the_component()
    {
        $this->fakeAccount();

        $forms = (new HubSpot)->render($this->form())->toArray()['props']['hubspotForms'];

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

        $properties = (new HubSpot)->render($this->form())->toArray()['props']['hubspotForms'][self::FORM]['properties'];

        $this->assertNotContains('email', array_column($properties, 'name'));
    }

    #[Test]
    public function it_still_renders_the_forms_when_the_subscription_lookup_fails()
    {
        Http::fake([
            'api.hubapi.com/marketing/v3/forms*' => Http::response(['results' => [$this->definition()]]),
            'api.hubapi.com/communication-preferences/*' => Http::response(['category' => 'MISSING_SCOPE'], 403),
        ]);

        $rendered = (new HubSpot)->render($this->form())->toArray();

        $this->assertNull($rendered['props']['apiError']);
        $this->assertCount(1, $rendered['props']['hubspotForms']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.hubspot.access_token' => null]);

        Http::fake();

        $props = (new HubSpot)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $rendered = (new HubSpot)->render($this->form())->toArray();

        $this->assertEquals('Could not resolve host.', $rendered['props']['apiError']);
        $this->assertEmpty($rendered['props']['hubspotForms']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_access_token()
    {
        $this->fakeAccount();

        (new HubSpot)->render($this->form());

        config(['forms-pro.connections.hubspot.access_token' => 'pat-eu1-different']);

        (new HubSpot)->render($this->form());

        Http::assertSentCount(4);
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
        Http::fake([
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
        $config = array_merge(['form' => self::FORM, 'email_field' => 'email'], $config);

        $form->connections($form->connections()->put('hubspot', $config))->save();

        return $form->makeSubmission()->data($data);
    }
}
