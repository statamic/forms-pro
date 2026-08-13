<?php

namespace Tests\FormConnections\Mailchimp;

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
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailchimp\SubscribeToMailchimp;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class SubscribeToMailchimpTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const string AUDIENCE = 'a1b2c3d4e5';

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.mailchimp.api_key' => 'mailchimp-key-us14']);
    }

    #[Test]
    public function it_upserts_the_member_against_the_hashed_email_address()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([])]);

        $this->subscribe(['email' => 'JACK@example.com']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && $request->url() === 'https://us14.api.mailchimp.com/3.0/lists/'.self::AUDIENCE.'/members/'.md5('jack@example.com')
                && $request->hasHeader('Authorization', 'Bearer mailchimp-key-us14')
                && $request['email_address'] === 'JACK@example.com';
        });
    }

    #[Test]
    public function it_never_sends_a_status()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('status', $payload);
    }

    #[Test]
    #[DataProvider('confirmationProvider')]
    public function it_maps_skip_confirmation_onto_status_if_new(bool $skip, string $expected)
    {
        $payload = $this->payload(['email' => 'jack@example.com'], ['skip_confirmation' => $skip]);

        $this->assertEquals($expected, $payload['status_if_new']);
    }

    public static function confirmationProvider(): array
    {
        return [
            'double opt-in' => [false, 'pending'],
            'skipped' => [true, 'subscribed'],
        ];
    }

    #[Test]
    public function it_sends_names_as_merge_fields()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => 'Jack', 'surname' => 'Black'],
            ['first_name_field' => 'first_name', 'last_name_field' => 'surname'],
        );

        $this->assertEquals(['FNAME' => 'Jack', 'LNAME' => 'Black'], $payload['merge_fields']);
    }

    #[Test]
    public function it_sends_configured_merge_fields_alongside_the_names()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'first_name' => 'Jack', 'company' => 'Statamic', 'interests' => ['news', 'offers']],
            [
                'first_name_field' => 'first_name',
                'merge_fields' => [
                    ['key' => 'COMPANY', 'value' => 'company'],
                    ['key' => 'INTERESTS', 'value' => 'interests'],
                ],
            ],
        );

        $this->assertEquals([
            'COMPANY' => 'Statamic',
            'INTERESTS' => 'news, offers',
            'FNAME' => 'Jack',
        ], $payload['merge_fields']);
    }

    #[Test]
    public function it_omits_merge_fields_when_none_are_mapped()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('merge_fields', $payload);
    }

    #[Test]
    public function it_sends_merge_fields_whose_value_is_zero()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'company' => '0'],
            ['merge_fields' => [['key' => 'COMPANY', 'value' => 'company']]],
        );

        $this->assertEquals(['COMPANY' => '0'], $payload['merge_fields']);
    }

    #[Test]
    public function it_omits_merge_fields_whose_form_field_was_left_blank()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'company' => ''],
            ['merge_fields' => [['key' => 'COMPANY', 'value' => 'company']]],
        );

        $this->assertArrayNotHasKey('merge_fields', $payload);
    }

    #[Test]
    public function it_adds_the_member_to_groups_matching_the_chosen_options()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'interests' => ['news']],
            ['groups' => [
                ['group' => '4e4b2bc6ae', 'field' => 'interests', 'option' => 'news'],
                ['group' => '3e1e51dbae', 'field' => 'interests', 'option' => 'offers'],
            ]],
        );

        $this->assertEquals(['4e4b2bc6ae' => true], $payload['interests']);
    }

    #[Test]
    public function it_adds_the_member_to_a_group_when_the_mapped_field_has_no_option()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'gdpr' => true],
            ['groups' => [['group' => '4e4b2bc6ae', 'field' => 'gdpr']]],
        );

        $this->assertEquals(['4e4b2bc6ae' => true], $payload['interests']);
    }

    #[Test]
    public function it_never_removes_the_member_from_a_group()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'interests' => []],
            ['groups' => [['group' => '4e4b2bc6ae', 'field' => 'interests', 'option' => 'news']]],
        );

        $this->assertArrayNotHasKey('interests', $payload);
    }

    #[Test]
    public function it_omits_groups_when_none_are_mapped()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('interests', $payload);
    }

    #[Test]
    #[DataProvider('marketingPermissionProvider')]
    public function it_records_marketing_permissions_from_the_mapped_field(mixed $value, bool $enabled)
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'gdpr' => $value],
            ['marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'gdpr']]],
        );

        $this->assertEquals(
            [['marketing_permission_id' => 'd60cef6b1f', 'enabled' => $enabled]],
            $payload['marketing_permissions'],
        );
    }

    public static function marketingPermissionProvider(): array
    {
        return [
            'checked' => [true, true],
            'unchecked' => [false, false],
            'yes' => ['yes', true],
            'no' => ['no', false],
            'blank' => ['', false],
            'chosen' => [['email'], true],
            'nothing chosen' => [[], false],
        ];
    }

    #[Test]
    public function it_records_every_mapped_marketing_permission()
    {
        $payload = $this->payload(
            ['email' => 'jack@example.com', 'gdpr' => true, 'interests' => []],
            ['marketing_permissions' => [
                ['permission' => 'd60cef6b1f', 'field' => 'gdpr'],
                ['permission' => '3560e0d4a2', 'field' => 'interests'],
            ]],
        );

        $this->assertEquals([
            ['marketing_permission_id' => 'd60cef6b1f', 'enabled' => true],
            ['marketing_permission_id' => '3560e0d4a2', 'enabled' => false],
        ], $payload['marketing_permissions']);
    }

    #[Test]
    public function it_omits_marketing_permissions_when_none_are_mapped()
    {
        $payload = $this->payload(['email' => 'jack@example.com']);

        $this->assertArrayNotHasKey('marketing_permissions', $payload);
    }

    #[Test]
    public function it_sends_marketing_permissions_in_the_same_request_as_the_member()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([])]);

        $this->subscribe(
            ['email' => 'jack@example.com', 'gdpr' => true],
            ['marketing_permissions' => [['permission' => 'd60cef6b1f', 'field' => 'gdpr']]],
        );

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_tags_the_member_in_a_second_request()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([])]);

        $this->subscribe(['email' => 'jack@example.com'], ['tags' => ['newsletter', 'website']]);

        Http::assertSentCount(2);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://us14.api.mailchimp.com/3.0/lists/'.self::AUDIENCE.'/members/'.md5('jack@example.com').'/tags'
                && $request['tags'] === [
                    ['name' => 'newsletter', 'status' => 'active'],
                    ['name' => 'website', 'status' => 'active'],
                ];
        });
    }

    #[Test]
    public function it_doesnt_make_a_tags_request_when_no_tags_are_configured()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([])]);

        $this->subscribe(['email' => 'jack@example.com']);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(null, 429, ['Retry-After' => 42])]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(42);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(MailchimpClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_mailchimp_is_unavailable()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(MailchimpClient::class));
    }

    #[Test]
    public function it_lets_connection_failures_bubble_up_for_the_queue_to_retry()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $this->expectException(ConnectionException::class);

        $this->subscribe(['email' => 'jack@example.com']);
    }

    #[Test]
    #[DataProvider('complianceProvider')]
    public function it_skips_contacts_in_a_compliance_state(string $title)
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([
            'title' => $title,
            'detail' => 'This contact previously unsubscribed.',
        ], 400)]);

        Log::shouldReceive('info')->once()->with(Mockery::pattern('/jack@example\.com/'));

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldNotReceive('fail');
        $queueJob->shouldNotReceive('release');

        $this->job(['email' => 'jack@example.com'])->setJob($queueJob)->handle(app(MailchimpClient::class));
    }

    public static function complianceProvider(): array
    {
        return [
            'unsubscribed' => ['Member In Compliance State'],
            'forgotten' => ['Forgotten Email Not Subscribed'],
        ];
    }

    #[Test]
    public function it_throws_on_an_invalid_merge_field()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([
            'title' => 'Invalid Resource',
            'detail' => 'Your merge fields were invalid.',
            'errors' => [['field' => 'COMPANY', 'message' => 'Please enter a value']],
        ], 400)]);

        $this->expectExceptionMessage('Your merge fields were invalid. Please enter a value');

        try {
            $this->subscribe(['email' => 'jack@example.com']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function it_throws_when_the_key_is_invalid()
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response(null, 401)]);

        $this->expectExceptionMessage('FORMS_PRO_MAILCHIMP_API_KEY');

        $this->subscribe(['email' => 'jack@example.com']);
    }

    #[Test]
    public function it_doesnt_subscribe_when_mailchimp_hasnt_been_configured()
    {
        config(['forms-pro.connections.mailchimp.api_key' => null]);

        Http::fake();

        $this->expectExceptionMessage('FORMS_PRO_MAILCHIMP_API_KEY');

        try {
            $this->subscribe(['email' => 'jack@example.com']);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function it_doesnt_subscribe_when_the_api_key_has_no_datacenter_suffix()
    {
        config(['forms-pro.connections.mailchimp.api_key' => 'keywithoutdatacenter']);

        Http::fake();

        $this->expectExceptionMessage('is invalid');

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
                ['handle' => 'gdpr', 'field' => ['type' => 'toggle']],
            ],
        ]))->save();
    }

    private function job(array $data, array $config = []): SubscribeToMailchimp
    {
        return new SubscribeToMailchimp(
            $this->form()->makeSubmission()->data($data),
            array_merge(['list' => self::AUDIENCE, 'email_field' => 'email'], $config),
        );
    }

    private function subscribe(array $data, array $config = []): void
    {
        $this->job($data, $config)->handle(app(MailchimpClient::class));
    }

    private function payload(array $data, array $config = []): array
    {
        Http::fake(['us14.api.mailchimp.com/*' => Http::response([])]);

        $this->subscribe($data, $config);

        return Http::recorded()->first()[0]->data();
    }
}
