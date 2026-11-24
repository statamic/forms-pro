<?php

namespace Tests\FormConnectors\Kit;

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
use Statamic\FormsPro\FormConnectors\Kit\KitClient;
use Statamic\FormsPro\FormConnectors\Kit\SubscribeToKit;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\AssertsFailedJobs;
use Tests\TestCase;

class SubscribeToKitTest extends TestCase
{
    use AssertsFailedJobs;
    use PreventsSavingStacheItemsToDisk;

    private const int FORM = 9785233;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.kit.api_key' => 'kit_apikey']);
    }

    #[Test]
    public function it_subscribes_the_submitter()
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => []])]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.kit.com/v4/subscribers'
                && $request->hasHeader('X-Kit-Api-Key', 'kit_apikey')
                && $request['email_address'] === 'jack@example.com';
        });
    }

    #[Test]
    public function it_doesnt_subscribe_when_the_email_field_is_blank()
    {
        Http::fake();

        $this->subscribe(['email' => null]);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_doesnt_send_a_state()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('state', $payload);
    }

    #[Test]
    public function it_sends_the_first_name_from_the_configured_field()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => 'Jack'],
            ['first_name_field' => 'first_name'],
        );

        $this->assertEquals('Jack', $payload['first_name']);
    }

    #[Test]
    public function it_omits_fields_that_havent_been_mapped()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertEquals(['email_address' => 'jack@example.com'], $payload);
    }

    #[Test]
    public function it_sends_custom_fields()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'surname' => 'Black', 'interests' => ['news', 'offers']],
            ['custom_fields' => [
                ['key' => 'last_name', 'value' => 'surname'],
                ['key' => 'interests', 'value' => 'interests'],
            ]],
        );

        $this->assertEquals([
            'last_name' => 'Black',
            'interests' => 'news, offers',
        ], $payload['fields']);
    }

    #[Test]
    public function it_sends_custom_fields_whose_value_is_zero()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'surname' => '0'],
            ['custom_fields' => [['key' => 'last_name', 'value' => 'surname']]],
        );

        $this->assertEquals(['last_name' => '0'], $payload['fields']);
    }

    #[Test]
    public function it_omits_custom_fields_whose_form_field_was_left_blank()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'surname' => ''],
            ['custom_fields' => [['key' => 'last_name', 'value' => 'surname']]],
        );

        $this->assertArrayNotHasKey('fields', $payload);
    }

    #[Test]
    public function it_adds_the_subscriber_to_the_configured_form()
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => []])]);

        $this->subscribe(['email' => 'jack@example.com'], ['form' => self::FORM]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.kit.com/v4/forms/'.self::FORM.'/subscribers'
                && $request['email_address'] === 'jack@example.com';
        });
    }

    #[Test]
    public function it_doesnt_add_the_subscriber_to_a_form_when_none_is_configured()
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => []])]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_applies_the_configured_tags()
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => []])]);

        $this->subscribe(['email' => 'jack@example.com'], ['tags' => [22367468, 22367469]]);

        Http::assertSentCount(3);

        foreach ([22367468, 22367469] as $tag) {
            Http::assertSent(function (Request $request) use ($tag) {
                return $request->url() === "https://api.kit.com/v4/tags/{$tag}/subscribers"
                    && $request['email_address'] === 'jack@example.com';
            });
        }
    }

    #[Test]
    public function it_logs_warnings_about_unknown_custom_fields()
    {
        Http::fake(['api.kit.com/*' => Http::response([
            'subscriber' => [],
            'warnings' => ['Unknown custom field key: "nope".'],
        ])]);

        Log::shouldReceive('info')->once()->with('Kit ignored some custom fields for [jack@example.com]: Unknown custom field key: "nope".');

        $this->subscribe(['email' => 'jack@example.com']);
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        Http::fake(['api.kit.com/*' => Http::response(null, 429, ['Retry-After' => 42])]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(42);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(KitClient::class));
    }

    #[Test]
    public function it_releases_the_job_for_a_minute_when_kit_sends_no_retry_after()
    {
        Http::fake(['api.kit.com/*' => Http::response(null, 429)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(60);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(KitClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_kit_is_unavailable()
    {
        Http::fake(['api.kit.com/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(KitClient::class));
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
        Http::fake(['api.kit.com/*' => Http::response($body, $status)]);

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), $message);

        Http::assertSentCount(1);
    }

    public static function fatalResponseProvider(): array
    {
        return [
            'invalid key' => [401, ['errors' => ['The access token is invalid']], 'FORMS_PRO_KIT_API_KEY'],
            'forbidden' => [403, null, 'FORMS_PRO_KIT_API_KEY'],
            'unknown form' => [404, ['errors' => ['Not Found']], 'no longer exists'],
            'invalid email' => [422, ['errors' => ['Email address is invalid']], 'Email address is invalid'],
        ];
    }

    #[Test]
    public function it_doesnt_subscribe_when_kit_hasnt_been_configured()
    {
        config(['statamic.forms-pro.connections.kit.api_key' => null]);

        Http::fake();

        $this->assertJobFails($this->job(['email' => 'jack@example.com']), 'FORMS_PRO_KIT_API_KEY');

        Http::assertNothingSent();
    }

    private function form(): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'first_name', 'field' => ['type' => 'text']],
                ['handle' => 'surname', 'field' => ['type' => 'text']],
                ['handle' => 'interests', 'field' => ['type' => 'checkboxes']],
            ],
        ]))->save();
    }

    private function job(array $data, array $config = []): SubscribeToKit
    {
        return new SubscribeToKit(
            $this->form()->makeSubmission()->data($data),
            array_merge(['email_field' => 'email'], $config),
        );
    }

    private function subscribe(array $data, array $config = []): void
    {
        $this->job($data, $config)->handle(app(KitClient::class));
    }

    private function payload(array $data, array $config = []): array
    {
        Http::fake(['api.kit.com/*' => Http::response(['subscriber' => []])]);

        $this->subscribe($data, $config);

        return Http::recorded()->first()[0]->data();
    }
}
