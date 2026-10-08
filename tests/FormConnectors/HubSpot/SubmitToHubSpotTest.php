<?php

namespace Tests\FormConnectors\HubSpot;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission as SubmissionContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\HubSpot\HubSpotClient;
use Statamic\FormsPro\FormConnectors\HubSpot\SubmitToHubSpot;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\AssertsFailedJobs;
use Tests\TestCase;

class SubmitToHubSpotTest extends TestCase
{
    use AssertsFailedJobs;
    use PreventsSavingStacheItemsToDisk;

    private const string FORM = '2cc5419c-d732-4a4a-a82d-c0bfbcf948a5';

    private const int PORTAL = 149072453;

    private const string SUBMIT_URL = 'https://api.hsforms.com/submissions/v3/integration/secure/submit/'.self::PORTAL.'/'.self::FORM;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.hubspot.access_token' => 'pat-eu1-token']);
    }

    #[Test]
    public function it_submits_the_email_address_to_the_configured_form()
    {
        $this->fake();

        $this->submit(['email' => 'jack@example.com']);

        Http::assertSent(function (Request $request) {
            return $request->url() === self::SUBMIT_URL
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer pat-eu1-token')
                && $request['fields'] === [['objectTypeId' => '0-1', 'name' => 'email', 'value' => 'jack@example.com']];
        });
    }

    #[Test]
    public function it_sends_the_mapped_properties()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => 'Jack', 'org' => 'Statamic'],
            ['properties' => [
                ['key' => 'firstname', 'value' => 'first_name'],
                ['key' => 'company', 'value' => 'org'],
            ]],
        );

        $this->assertEquals([
            ['objectTypeId' => '0-1', 'name' => 'email', 'value' => 'jack@example.com'],
            ['objectTypeId' => '0-1', 'name' => 'firstname', 'value' => 'Jack'],
            ['objectTypeId' => '0-1', 'name' => 'company', 'value' => 'Statamic'],
        ], $payload['fields']);
    }

    #[Test]
    public function it_resolves_object_type_ids_from_the_form_definition()
    {
        $definition = $this->definition();
        $definition['fieldGroups'][0]['fields'][] = [
            'objectTypeId' => '0-2', 'name' => 'name', 'label' => 'Company Name', 'required' => false, 'hidden' => false,
        ];

        $this->fake(definition: $definition);

        $payload = $this->payload(
            ['email' => 'jack@example.com', 'org' => 'Statamic'],
            ['properties' => [['key' => 'name', 'value' => 'org']]],
            fake: false,
        );

        $this->assertEquals(['objectTypeId' => '0-2', 'name' => 'name', 'value' => 'Statamic'], $payload['fields'][1]);
    }

    #[Test]
    public function it_skips_properties_whose_form_field_is_empty()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => ''],
            ['properties' => [['key' => 'firstname', 'value' => 'first_name']]],
        );

        $this->assertCount(1, $payload['fields']);
    }

    #[Test]
    public function it_sends_properties_whose_value_is_zero()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => '0'],
            ['properties' => [['key' => 'firstname', 'value' => 'first_name']]],
        );

        $this->assertEquals('0', $payload['fields'][1]['value']);
    }

    #[Test]
    public function it_sends_the_submission_date_as_a_string_of_milliseconds()
    {
        $this->fake();

        $submission = $this->submission(['email' => 'jack@example.com']);

        (new SubmitToHubSpot($submission, ['form' => self::FORM, 'email_field' => 'email']))->handle(app(HubSpotClient::class));

        $this->assertSame((string) $submission->date()->getTimestampMs(), $this->submitted()['submittedAt']);
    }

    #[Test]
    public function it_omits_the_submission_date_when_its_over_a_month_old()
    {
        $this->fake();

        $submission = $this->submission(['email' => 'jack@example.com'])->id((string) now()->subMonths(2)->timestamp);

        (new SubmitToHubSpot($submission, ['form' => self::FORM, 'email_field' => 'email']))->handle(app(HubSpotClient::class));

        $this->assertArrayNotHasKey('submittedAt', $this->submitted());
    }

    #[Test]
    public function it_sends_the_tracking_context()
    {
        $context = ['hutk' => str_repeat('a', 32), 'ipAddress' => '1.2.3.4', 'pageUri' => 'https://example.com/contact'];

        $this->fake();

        (new SubmitToHubSpot($this->submission(['email' => 'jack@example.com']), ['form' => self::FORM, 'email_field' => 'email'], $context))
            ->handle(app(HubSpotClient::class));

        $this->assertEquals($context, $this->submitted()['context']);
    }

    #[Test]
    public function it_omits_the_context_when_nothing_was_captured()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('context', $payload);
    }

    #[Test]
    public function it_survives_queue_serialization_with_context()
    {
        $job = new SubmitToHubSpot(
            $this->submission(['email' => 'jack@example.com']),
            ['form' => self::FORM, 'email_field' => 'email'],
            ['ipAddress' => '1.2.3.4'],
        );

        $this->assertEquals(['ipAddress' => '1.2.3.4'], unserialize(serialize($job))->context);
    }

    #[Test]
    public function it_omits_legal_consent_options_for_a_form_that_doesnt_collect_consent()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('legalConsentOptions', $payload);
    }

    #[Test]
    public function it_ignores_stale_consent_config_for_a_form_that_doesnt_collect_consent()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'marketing' => true],
            [
                'consent_to_process_field' => 'marketing',
                'legitimate_interest_subscription' => 3314676590,
                'communications' => [
                    ['subscription' => 3314676590, 'field' => 'marketing'],
                ],
            ],
        );

        $this->assertArrayNotHasKey('legalConsentOptions', $payload);
    }

    #[Test]
    public function it_sends_communication_consent_from_the_mapped_fields()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => $this->explicitConsent()]));

        $payload = $this->payload(
            ['email' => 'jack@example.com', 'marketing' => true, 'sales' => false],
            [
                'consent_to_process_field' => 'marketing',
                'communications' => [
                    ['subscription' => 3314676590, 'field' => 'marketing'],
                    ['subscription' => 3314676593, 'field' => 'sales'],
                ],
            ],
            fake: false,
        );

        $this->assertTrue($payload['legalConsentOptions']['consent']['consentToProcess']);
        $this->assertEquals('We need your permission.', $payload['legalConsentOptions']['consent']['text']);
        $this->assertEquals([
            ['value' => true, 'subscriptionTypeId' => 3314676590, 'text' => 'I agree to receive communications.'],
            ['value' => false, 'subscriptionTypeId' => 3314676593, 'text' => 'I agree to receive communications.'],
        ], $payload['legalConsentOptions']['consent']['communications']);
    }

    #[Test]
    #[DataProvider('declinedConsentProvider')]
    public function it_doesnt_submit_when_the_submitter_declined_consent_to_process(array $data)
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => $this->explicitConsent()]));

        Log::shouldReceive('info')->once()->with(Mockery::on(fn (string $message) => str_contains(
            $message, 'Skipped submitting [jack@example.com] to HubSpot: The submitter didn\'t consent'
        )));

        $this->submit($data, ['consent_to_process_field' => 'marketing']);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'hsforms'));
    }

    public static function declinedConsentProvider(): array
    {
        return [
            'unticked' => [['email' => 'jack@example.com', 'marketing' => false]],
            'left empty' => [['email' => 'jack@example.com']],
            'answered no' => [['email' => 'jack@example.com', 'marketing' => 'no']],
        ];
    }

    #[Test]
    public function it_fails_when_the_form_needs_consent_to_process_and_no_field_is_mapped()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => $this->explicitConsent()]));

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), 'no field is mapped to it');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'hsforms'));
    }

    #[Test]
    public function it_never_sends_the_subscription_name_as_the_consent_text()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => $this->explicitConsent()]));

        $payload = $this->payload(
            ['email' => 'jack@example.com', 'marketing' => true],
            [
                'consent_to_process_field' => 'marketing',
                'communications' => [['subscription' => 3314676590, 'field' => 'marketing']],
            ],
            fake: false,
        );

        $this->assertEquals('I agree to receive communications.', $payload['legalConsentOptions']['consent']['communications'][0]['text']);
    }

    #[Test]
    public function it_omits_communications_that_havent_been_mapped()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => $this->explicitConsent()]));

        $payload = $this->payload(
            ['email' => 'jack@example.com', 'marketing' => true],
            [
                'consent_to_process_field' => 'marketing',
                'communications' => [['subscription' => 3314676590, 'field' => 'marketing']],
            ],
            fake: false,
        );

        $this->assertCount(1, $payload['legalConsentOptions']['consent']['communications']);
    }

    #[Test]
    public function it_sends_implicit_consent_without_any_mapped_communications()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => [
            'type' => 'implicit_consent_to_process',
            'consentToProcessText' => 'By submitting, you agree.',
            'communicationsCheckboxes' => [],
        ]]));

        $payload = $this->payload(['email' => 'jack@example.com'], fake: false);

        $this->assertTrue($payload['legalConsentOptions']['consent']['consentToProcess']);
        $this->assertEquals('By submitting, you agree.', $payload['legalConsentOptions']['consent']['text']);
    }

    #[Test]
    public function it_sends_legitimate_interest_without_any_mapped_field()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => [
            'type' => 'legitimate_interest',
            'lawfulBasis' => 'lead',
            'privacyText' => 'We contact you about our products.',
            'subscriptionTypeIds' => [3314676590],
        ]]));

        $payload = $this->payload(['email' => 'jack@example.com'], fake: false);

        $this->assertEquals([
            'value' => true,
            'subscriptionTypeId' => 3314676590,
            'legalBasis' => 'LEAD',
            'text' => 'We contact you about our products.',
        ], $payload['legalConsentOptions']['legitimateInterest']);
    }

    #[Test]
    public function it_omits_the_subscription_when_the_form_declares_several_and_none_is_chosen()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => [
            'type' => 'legitimate_interest',
            'lawfulBasis' => 'lead',
            'privacyText' => 'We contact you about our products.',
            'subscriptionTypeIds' => [3314676590, 3314676593],
        ]]));

        $payload = $this->payload(['email' => 'jack@example.com'], fake: false);

        $this->assertArrayNotHasKey('subscriptionTypeId', $payload['legalConsentOptions']['legitimateInterest']);
    }

    #[Test]
    public function it_ignores_a_subscription_that_isnt_on_the_form()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => [
            'type' => 'legitimate_interest',
            'lawfulBasis' => 'lead',
            'privacyText' => 'We contact you about our products.',
            'subscriptionTypeIds' => [3314676590],
        ]]));

        $payload = $this->payload(
            ['email' => 'jack@example.com'],
            ['legitimate_interest_subscription' => 999999999],
            fake: false,
        );

        $this->assertEquals(3314676590, $payload['legalConsentOptions']['legitimateInterest']['subscriptionTypeId']);
    }

    #[Test]
    public function it_sends_the_chosen_subscription_for_legitimate_interest_with_several_ids()
    {
        $this->fake(definition: $this->definition(['legalConsentOptions' => [
            'type' => 'legitimate_interest',
            'lawfulBasis' => 'client',
            'privacyText' => 'We contact you about our products.',
            'subscriptionTypeIds' => [3314676590, 3314676593],
        ]]));

        $payload = $this->payload(
            ['email' => 'jack@example.com'],
            ['legitimate_interest_subscription' => 3314676593],
            fake: false,
        );

        $this->assertEquals(3314676593, $payload['legalConsentOptions']['legitimateInterest']['subscriptionTypeId']);
        $this->assertEquals('CUSTOMER', $payload['legalConsentOptions']['legitimateInterest']['legalBasis']);
    }

    #[Test]
    #[DataProvider('rateLimitProvider')]
    public function it_releases_the_job_when_rate_limited(?array $body, array $headers, int $expected)
    {
        $this->fake(submit: Http::response($body, 429, $headers));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with($expected);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(HubSpotClient::class));
    }

    public static function rateLimitProvider(): array
    {
        return [
            'honours retry-after' => [null, ['Retry-After' => 42], 42],
            'burst policy' => [['policyName' => 'SECONDLY'], [], 10],
            'no policy' => [null, [], 10],
            'daily policy' => [['policyName' => 'DAILY'], [], 3600],
        ];
    }

    #[Test]
    public function it_releases_the_job_when_a_migration_is_in_progress()
    {
        $this->fake(submit: Http::response(null, 477, ['Retry-After' => 90]));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(90);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(HubSpotClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_hubspot_is_unavailable()
    {
        $this->fake(submit: Http::response(null, 503));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(HubSpotClient::class));
    }

    #[Test]
    public function it_names_the_missing_scope_when_hubspot_rejects_the_token()
    {
        $this->fake(submit: Http::response([
            'category' => 'MISSING_SCOPE',
            'errors' => [['context' => ['requiredGranularScopes' => ['forms']]]],
        ], 403));

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), 'It needs one of: forms.');
    }

    #[Test]
    #[DataProvider('fatalResponseProvider')]
    public function it_fails_the_job_on_fatal_errors(int $status, ?array $body, string $message)
    {
        $this->fake(submit: Http::response($body, $status));

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), $message);
    }

    public static function fatalResponseProvider(): array
    {
        return [
            'invalid token' => [401, null, 'FORMS_PRO_HUBSPOT_ACCESS_TOKEN'],
            'unknown form' => [404, null, 'no longer exists'],
            'missing required field' => [400, ['errors' => [[
                'message' => "Error in 'fields.company'. Required field 'company' is missing",
                'errorType' => 'REQUIRED_FIELD',
            ]]], "Required field 'company' is missing"],
            'recaptcha enabled' => [400, ['errors' => [[
                'message' => 'The form has reCAPTCHA enabled.',
                'errorType' => 'FORM_HAS_RECAPTCHA_ENABLED',
            ]]], 'reCAPTCHA'],
        ];
    }

    #[Test]
    #[DataProvider('skippableErrorProvider')]
    public function it_doesnt_fail_the_job_on_per_submission_data_errors(string $errorType)
    {
        $this->fake(submit: Http::response(['errors' => [['message' => 'Nope.', 'errorType' => $errorType]]], 400));

        Log::shouldReceive('info')->once()->with(Mockery::on(fn (string $message) => str_contains($message, 'Skipped submitting [jack@example.com]')));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldNotReceive('fail');

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(HubSpotClient::class));
    }

    public static function skippableErrorProvider(): array
    {
        return [['INVALID_EMAIL'], ['BLOCKED_EMAIL'], ['INVALID_NUMBER'], ['NUMBER_OUT_OF_RANGE']];
    }

    #[Test]
    public function it_lets_connection_failures_bubble_up_for_the_queue_to_retry()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $this->expectException(ConnectionException::class);

        $this->submit(['email' => 'jack@example.com']);
    }

    #[Test]
    public function it_doesnt_submit_when_hubspot_hasnt_been_configured()
    {
        config(['statamic.forms-pro.connections.hubspot.access_token' => null]);

        Http::fake();

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), 'FORMS_PRO_HUBSPOT_ACCESS_TOKEN');

        Http::assertNothingSent();
    }

    private function explicitConsent(): array
    {
        return [
            'type' => 'explicit_consent_to_process',
            'consentToProcessText' => 'We need your permission.',
            'privacyText' => 'We care about your privacy.',
            'communicationsCheckboxes' => [
                ['required' => true, 'subscriptionTypeId' => 3314676590, 'label' => 'I agree to receive communications.'],
                ['required' => false, 'subscriptionTypeId' => 3314676593, 'label' => 'I agree to receive communications.'],
            ],
        ];
    }

    private function definition(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    private function fake(?array $definition = null, mixed $submit = null): void
    {
        Http::fake([
            'api.hubapi.com/account-info/*' => Http::response(['portalId' => self::PORTAL]),
            'api.hubapi.com/marketing/v3/forms/*' => Http::response($definition ?? $this->definition()),
            'api.hsforms.com/*' => $submit ?? Http::response(['inlineMessage' => '']),
        ]);
    }

    private function form(): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'first_name', 'field' => ['type' => 'text']],
                ['handle' => 'org', 'field' => ['type' => 'text']],
                ['handle' => 'marketing', 'field' => ['type' => 'toggle']],
                ['handle' => 'sales', 'field' => ['type' => 'toggle']],
            ],
        ]))->save();
    }

    private function submission(array $data): SubmissionContract
    {
        return $this->form()->makeSubmission()->data($data);
    }

    private function job(array $data, array $config = []): SubmitToHubSpot
    {
        return new SubmitToHubSpot(
            $this->submission($data),
            array_merge(['form' => self::FORM, 'email_field' => 'email'], $config),
        );
    }

    private function submit(array $data, array $config = []): void
    {
        $this->job($data, $config)->handle(app(HubSpotClient::class));
    }

    private function submitted(): array
    {
        return Http::recorded()
            ->first(fn (array $pair) => str_contains($pair[0]->url(), 'hsforms'))[0]
            ->data();
    }

    private function payload(array $data, array $config = [], bool $fake = true): array
    {
        if ($fake) {
            $this->fake();
        }

        $this->submit($data, $config);

        return $this->submitted();
    }
}
