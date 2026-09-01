<?php

namespace Tests\FormConnections\Twilio;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Twilio\SendTwilioMessage;
use Statamic\FormsPro\FormConnections\Twilio\TwilioClient;
use Statamic\FormsPro\FormConnections\Twilio\TwilioException;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class SendTwilioMessageTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const string ACCOUNT_SID = 'AC00000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic.forms-pro.connections.twilio.account_sid' => self::ACCOUNT_SID,
            'statamic.forms-pro.connections.twilio.api_key' => 'SK00000000000000000000000000000000',
            'statamic.forms-pro.connections.twilio.api_secret' => 'secret',
        ]);
    }

    #[Test]
    public function it_sends_the_message_to_twilio()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

        $this->send($this->form(), ['name' => 'Jack Black']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::ACCOUNT_SID.'/Messages.json'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('SK00000000000000000000000000000000:secret'))
                && $request['To'] === '+447700900123'
                && $request['From'] === '+14155552671'
                && $request['Body'] === 'New enquiry';
        });
    }

    #[Test]
    public function it_sends_from_a_messaging_service()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $this->send($this->form(), ['name' => 'Jack Black'], ['from' => 'MG0123456789abcdef0123456789abcdef']);

        Http::assertSent(function (Request $request) {
            return $request['MessagingServiceSid'] === 'MG0123456789abcdef0123456789abcdef'
                && ! isset($request['From']);
        });
    }

    #[Test]
    public function it_parses_antlers_in_the_body()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

        $this->send($this->form(), ['name' => 'Jack Black'], ['body' => 'New enquiry from {{ name }}']);

        Http::assertSent(fn (Request $request) => $request['Body'] === 'New enquiry from Jack Black');
    }

    #[Test]
    public function it_texts_the_number_from_a_form_field()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $this->send($this->form(), ['phone' => '+447700900999'], ['to' => 'field:phone']);

        Http::assertSent(fn (Request $request) => $request['To'] === '+447700900999');
    }

    #[Test]
    #[DataProvider('numberProvider')]
    public function it_normalizes_submitted_numbers(string $value, ?string $countryCode, ?string $expected)
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        $this->send($this->form(), ['phone' => $value], ['to' => 'field:phone', 'country_code' => $countryCode]);

        if ($expected === null) {
            Http::assertNothingSent();

            return;
        }

        Http::assertSent(fn (Request $request) => $request['To'] === $expected);
    }

    public static function numberProvider(): array
    {
        return [
            'already e164' => ['+447700900123', null, '+447700900123'],
            'e164 with spaces' => ['+44 7700 900123', null, '+447700900123'],
            'national with dialling code' => ['07700 900123', '+44', '+447700900123'],
            'national without dialling code' => ['07700 900123', null, null],
            'international prefix' => ['00447700900123', null, '+447700900123'],
            'us formatting' => ['(555) 867-5309', '+1', '+15558675309'],
            'implausibly short' => ['12', '+44', '+4412'],
            'not a number' => ['nope', '+44', null],
            'empty' => ['', '+44', null],
            'punctuation only' => ['()-', '+44', null],
        ];
    }

    #[Test]
    public function it_doesnt_send_when_the_submitted_number_is_missing()
    {
        Http::fake();

        $this->send($this->form(), ['phone' => null], ['to' => 'field:phone']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_doesnt_send_when_the_body_is_blank()
    {
        Http::fake();

        $this->send($this->form(), ['name' => 'Jack Black'], ['body' => '   ']);

        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('skippableErrorProvider')]
    public function it_skips_errors_that_wont_resolve_by_retrying(int $code)
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => $code, 'message' => 'Nope.'], 400)]);

        $this->send($this->form(), ['name' => 'Jack Black']);

        Http::assertSentCount(1);
    }

    public static function skippableErrorProvider(): array
    {
        return [
            'invalid to number' => [21211],
            'unreachable number' => [21214],
            'unsubscribed recipient' => [21610],
            'landline' => [21614],
            'body too long' => [21617],
        ];
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20429], 429, ['Retry-After' => 42])]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(42);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(TwilioClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_twilio_is_unavailable()
    {
        Http::fake(['api.twilio.com/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(TwilioClient::class));
    }

    #[Test]
    public function it_throws_when_the_credentials_are_invalid()
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20003], 401)]);

        $this->expectExceptionMessage('FORMS_PRO_TWILIO_API_KEY');

        $this->send($this->form(), ['name' => 'Jack Black']);
    }

    #[Test]
    public function it_throws_when_the_sender_is_misconfigured()
    {
        Http::fake(['api.twilio.com/*' => Http::response([
            'code' => 21606,
            'message' => "The 'From' number is not a valid message-capable Twilio number.",
        ], 400)]);

        $this->expectException(TwilioException::class);

        $this->send($this->form(), ['name' => 'Jack Black']);
    }

    #[Test]
    public function it_doesnt_send_when_no_credentials_have_been_configured()
    {
        config(['statamic.forms-pro.connections.twilio.api_secret' => null]);

        Http::fake();

        $this->expectExceptionMessage('FORMS_PRO_TWILIO_ACCOUNT_SID');

        try {
            $this->send($this->form(), ['name' => 'Jack Black']);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function form(): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'phone', 'field' => ['type' => 'phone']],
            ],
        ]))->save();
    }

    private function submission(FormContract $form, array $data): Submission
    {
        return $form->makeSubmission()->data($data);
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'id' => 'abc',
            'to' => '+447700900123',
            'from' => '+14155552671',
            'body' => 'New enquiry',
        ], $overrides);
    }

    private function job(FormContract $form, array $data = [], array $config = []): SendTwilioMessage
    {
        return new SendTwilioMessage($this->submission($form, $data), $this->config($config));
    }

    private function send(FormContract $form, array $data, array $config = []): void
    {
        $this->job($form, $data, $config)->handle(app(TwilioClient::class));
    }
}
