<?php

namespace Tests\FormConnectors\Mailcoach;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Mailcoach\MailcoachClient;
use Statamic\FormsPro\FormConnectors\Mailcoach\SubscribeToMailcoach;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class SubscribeToMailcoachTest extends TestCase
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
    public function it_subscribes_the_submitter_to_the_list()
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => []])]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://acme.mailcoach.app/api/email-lists/'.self::LIST.'/subscribers'
                && $request->hasHeader('Authorization', 'Bearer mailcoach-token')
                && $request['email'] === 'jack@example.com';
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
    public function it_sends_the_configured_tags()
    {
        $payload = $this->payload(['email' => 'jack@example.com'], ['tags' => ['newsletter', 'website']]);

        $this->assertEquals(['newsletter', 'website'], $payload['tags']);
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
    public function it_throws_on_fatal_errors(int $status, ?array $body, string $message)
    {
        Http::fake(['acme.mailcoach.app/*' => Http::response($body, $status)]);

        $this->expectExceptionMessage($message);

        try {
            $this->subscribe(['email' => 'jack@example.com']);
        } finally {
            Http::assertSentCount(1);
        }
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

        $this->expectExceptionMessage('FORMS_PRO_MAILCOACH_TOKEN');

        try {
            $this->subscribe(['email' => 'jack@example.com']);
        } finally {
            Http::assertNothingSent();
        }
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
        Http::fake(['acme.mailcoach.app/*' => Http::response(['data' => []])]);

        $this->subscribe($data, $config);

        return Http::recorded()->first()[0]->data();
    }
}
