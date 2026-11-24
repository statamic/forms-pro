<?php

namespace Tests\FormConnectors\Mailcoach;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnectors\Mailcoach\SubscribeToMailcoach;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\AssertsFailedJobs;
use Tests\TestCase;

class SubscribeToMailcoachTest extends TestCase
{
    use AssertsFailedJobs;
    use PreventsSavingStacheItemsToDisk;

    private const string LIST = '9dcbb6dc-1b47-4d2e-9d3f-8dbf1a92a5f1';

    private const string LOOKUP = 'acme.mailcoach.app/api/email-lists/*/subscribers?*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.forms-pro.connections.mailcoach.api_url' => 'https://acme.mailcoach.app',
            'statamic.forms-pro.connections.mailcoach.token' => 'mailcoach-token',
        ]);
    }

    #[Test]
    public function it_subscribes_the_submitter_to_the_list()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://acme.mailcoach.app/api/email-lists/'.self::LIST.'/subscribers'
                && $request->hasHeader('Authorization', 'Bearer mailcoach-token')
                && $request['email'] === 'jack@example.com';
        });
    }

    #[Test]
    public function it_looks_up_whether_the_submitter_has_unsubscribed()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://acme.mailcoach.app/api/email-lists/'.self::LIST.'/subscribers?')
            && $request['filter'] === ['email' => 'jack@example.com', 'status' => 'unsubscribed']);
    }

    #[Test]
    public function it_doesnt_resubscribe_someone_who_has_unsubscribed()
    {
        Log::spy();

        Http::fake([
            self::LOOKUP => Http::response(['data' => [['uuid' => 'subscriber-uuid', 'email' => 'Jack@Example.com']]]),
            'acme.mailcoach.app/*' => Http::response(['data' => ['uuid' => 'subscriber-uuid']]),
        ]);

        $this->subscribe(['email' => 'jack@example.com'], ['skip_confirmation' => true, 'tags' => ['newsletter']]);

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        Log::shouldHaveReceived('info')->once()->with(Mockery::pattern('/Skipped subscribing \\[jack@example.com\\] to Mailcoach/'));
    }

    #[Test]
    public function it_still_updates_existing_subscribers_who_havent_unsubscribed()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/api/email-lists/*' => Http::response(['data' => ['uuid' => 'subscriber-uuid']]),
            'acme.mailcoach.app/api/subscribers/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe(['email' => 'jack@example.com', 'first_name' => 'Jack'], ['first_name_field' => 'first_name', 'tags' => ['newsletter']]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://acme.mailcoach.app/api/email-lists/'.self::LIST.'/subscribers'
            && $request['first_name'] === 'Jack');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://acme.mailcoach.app/api/subscribers/subscriber-uuid/tags');
    }

    #[Test]
    public function it_ignores_unsubscribed_matches_for_a_different_email()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => [['uuid' => 'other-uuid', 'email' => 'jack@example.com.au']]]),
            'acme.mailcoach.app/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://acme.mailcoach.app/api/email-lists/'.self::LIST.'/subscribers');
    }

    #[Test]
    public function it_doesnt_subscribe_when_the_email_field_is_blank()
    {
        Http::fake();

        $this->subscribe(['email' => null]);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_sends_the_names_from_the_configured_fields()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => 'Jack', 'surname' => 'Black'],
            ['first_name_field' => 'first_name', 'last_name_field' => 'surname'],
        );

        $this->assertEquals('Jack', $payload['first_name']);
        $this->assertEquals('Black', $payload['last_name']);
    }

    #[Test]
    public function it_omits_fields_that_havent_been_mapped()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertEquals(['email' => 'jack@example.com'], $payload);
    }

    #[Test]
    public function it_adds_the_configured_tags_without_replacing_existing_ones()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/api/email-lists/*' => Http::response(['data' => ['uuid' => 'subscriber-uuid']]),
            'acme.mailcoach.app/api/subscribers/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe(['email' => 'jack@example.com'], ['tags' => ['newsletter', 'website']]);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/email-lists/') && ! isset($request['tags']));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://acme.mailcoach.app/api/subscribers/subscriber-uuid/tags'
            && $request['tags'] === ['newsletter', 'website']);
    }

    #[Test]
    public function it_doesnt_add_tags_when_none_are_configured()
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/*' => Http::response(['data' => ['uuid' => 'subscriber-uuid']]),
        ]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_sends_extra_attributes()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'company' => 'Statamic', 'interests' => ['news', 'offers']],
            ['attributes' => [
                ['key' => 'company_name', 'value' => 'company'],
                ['key' => 'interests', 'value' => 'interests'],
            ]],
        );

        $this->assertEquals([
            'company_name' => 'Statamic',
            'interests' => 'news, offers',
        ], $payload['extra_attributes']);
    }

    #[Test]
    public function it_omits_incomplete_attributes()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com'],
            ['attributes' => [
                ['key' => '', 'value' => 'company'],
                ['key' => 'plan', 'value' => ''],
            ]],
        );

        $this->assertArrayNotHasKey('extra_attributes', $payload);
    }

    #[Test]
    public function it_sends_attributes_whose_value_is_zero()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'company' => '0'],
            ['attributes' => [['key' => 'company_name', 'value' => 'company']]],
        );

        $this->assertEquals(['company_name' => '0'], $payload['extra_attributes']);
    }

    #[Test]
    public function it_omits_attributes_whose_form_field_was_left_blank()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'company' => ''],
            ['attributes' => [['key' => 'company_name', 'value' => 'company']]],
        );

        $this->assertArrayNotHasKey('extra_attributes', $payload);
    }

    #[Test]
    public function it_skips_the_confirmation_email_when_enabled()
    {
        $payload = $this->payload(['email' => 'jack@example.com'], ['skip_confirmation' => true]);

        $this->assertTrue($payload['skip_confirmation']);
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(null, 429, ['Retry-After' => 42])]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(42);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(MailcoachClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_mailcoach_is_unavailable()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(MailcoachClient::class));
    }

    #[Test]
    public function it_lets_connection_failures_bubble_up_for_the_queue_to_retry()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $this->expectException(ConnectionException::class);

        $this->subscribe(['email' => 'jack@example.com']);
    }

    #[Test]
    #[DataProvider('fatalResponseProvider')]
    public function it_fails_the_job_on_fatal_errors(int $status, ?array $body, string $message)
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/*' => Http::response($body, $status),
        ]);

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), $message);

        Http::assertSentCount(2);
    }

    public static function fatalResponseProvider(): array
    {
        return [
            'invalid token' => [401, null, 'FORMS_PRO_MAILCOACH_TOKEN'],
            'forbidden' => [403, null, 'FORMS_PRO_MAILCOACH_TOKEN'],
            'invalid email' => [422, ['errors' => ['email' => ['The email must be a valid email address.']]], 'valid email address'],
            'unknown list' => [404, null, '[404]'],
        ];
    }

    #[Test]
    public function it_doesnt_subscribe_when_mailcoach_hasnt_been_configured()
    {
        config(['statamic.forms-pro.connections.mailcoach.token' => null]);

        Http::fake();

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), 'FORMS_PRO_MAILCOACH_TOKEN');

        Http::assertNothingSent();
    }

    private function form(): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'first_name', 'field' => ['type' => 'text']],
                ['handle' => 'surname', 'field' => ['type' => 'text']],
                ['handle' => 'company', 'field' => ['type' => 'text']],
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes']],
            ],
        ]))->save();
    }

    private function job(array $data, array $config = []): SubscribeToMailcoach
    {
        return new SubscribeToMailcoach(
            $this->form()->makeSubmission()->data($data),
            array_merge(['list' => self::LIST, 'email_field' => 'email'], $config),
        );
    }

    private function subscribe(array $data, array $config = []): void
    {
        $this->job($data, $config)->handle(app(MailcoachClient::class));
    }

    private function payload(array $data, array $config = []): array
    {
        Http::fake([
            self::LOOKUP => Http::response(['data' => []]),
            'acme.mailcoach.app/*' => Http::response(['data' => []]),
        ]);

        $this->subscribe($data, $config);

        return Http::recorded(fn (Request $request) => $request->method() === 'POST')->first()[0]->data();
    }
}
