<?php

namespace Tests\FormConnections\Twilio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Twilio;
use Statamic\FormsPro\FormConnections\Twilio\SendTwilioMessage;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class TwilioConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const ACCOUNT_SID = 'AC00000000000000000000000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'forms-pro.connections.twilio.account_sid' => self::ACCOUNT_SID,
            'forms-pro.connections.twilio.api_key' => 'SK00000000000000000000000000000000',
            'forms-pro.connections.twilio.api_secret' => 'secret',
        ]);
    }

    #[Test]
    public function it_returns_a_job_per_twilio_notification()
    {
        $form = $this->form([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
            ['id' => 'def', 'to' => 'field:phone', 'from' => '+14155552671', 'body' => 'Thanks!'],
        ]);

        $jobs = (new Twilio)->finalized($form->makeSubmission()->data(['phone' => '07700 900123']));

        $this->assertCount(2, $jobs);
        $this->assertContainsOnlyInstancesOf(SendTwilioMessage::class, $jobs);
        $this->assertEquals('+447700900123', $jobs[0]->config['to']);
        $this->assertEquals('field:phone', $jobs[1]->config['to']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_has_no_twilio_notifications()
    {
        $this->assertEmpty((new Twilio)->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_skips_disabled_notifications()
    {
        $form = $this->form([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'One', 'enabled' => false],
            ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'Two'],
        ]);

        $jobs = (new Twilio)->finalized($form->makeSubmission()->data([]));

        $this->assertCount(1, $jobs);
        $this->assertEquals('+447700900124', $jobs[0]->config['to']);
    }

    #[Test]
    public function it_filters_notifications_using_conditions()
    {
        $form = $this->form([[
            'id' => 'abc',
            'to' => '+447700900123',
            'from' => '+14155552671',
            'body' => 'New enquiry',
            'conditions' => [['field' => 'enquiry_type', 'operator' => 'equals', 'value' => 'sales']],
        ]]);

        $this->assertCount(1, (new Twilio)->finalized($form->makeSubmission()->data(['enquiry_type' => 'sales'])));
        $this->assertCount(0, (new Twilio)->finalized($form->makeSubmission()->data(['enquiry_type' => 'support'])));
    }

    #[Test]
    public function it_counts_notifications()
    {
        $this->assertEquals(0, (new Twilio)->count(tap(Form::make('test'))->save()));
        $this->assertEquals(1, (new Twilio)->count($this->form([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
        ])));
    }

    #[Test]
    public function it_renders_the_vue_component()
    {
        Http::fake([
            'api.twilio.com/*/IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => [
                ['phone_number' => '+14155552671', 'friendly_name' => 'Marketing', 'capabilities' => ['sms' => true]],
                ['phone_number' => '+447458158271', 'friendly_name' => '447458158271', 'capabilities' => ['sms' => true]],
                ['phone_number' => '+14155553333', 'friendly_name' => '(415) 555-3333', 'capabilities' => ['sms' => true]],
                ['phone_number' => '+14155559999', 'friendly_name' => 'Fax only', 'capabilities' => ['sms' => false]],
            ]]),
            'messaging.twilio.com/v1/Services*' => Http::response(['services' => [
                ['sid' => 'MG0123456789abcdef0123456789abcdef', 'friendly_name' => 'Main'],
            ]]),
        ]);

        $component = (new Twilio)->render($this->form())->toArray();

        $this->assertEquals('twilio-connection', $component['name']);
        $this->assertEquals(cp_route('forms.connect.twilio.update', $this->form()->handle()), $component['props']['action']);
        $this->assertTrue($component['props']['isConfigured']);

        $this->assertEquals([
            '+14155552671' => '+14155552671 (Marketing)',
            '+14155553333' => '+14155553333',
            '+447458158271' => '+447458158271',
            'MG0123456789abcdef0123456789abcdef' => 'Main',
        ], collect($component['props']['defaults']['meta']['from']['options'])->pluck('label', 'value')->all());
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.twilio' => null]);

        Http::fake();

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_only_asks_twilio_for_resources_a_standard_api_key_can_reach()
    {
        Http::fake([
            'api.twilio.com/*/IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => []]),
            'messaging.twilio.com/v1/Services*' => Http::response(['services' => []]),
        ]);

        (new Twilio)->render($this->form());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::ACCOUNT_SID.'.json'));
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['*' => Http::response(['code' => 20003], 401)]);

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertEquals('The [FORMS_PRO_TWILIO_API_KEY] and [FORMS_PRO_TWILIO_API_SECRET] environment variables are invalid.', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['from']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_twilio_is_unavailable()
    {
        Http::fake(['*' => Http::response(null, 503)]);

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertEquals('Twilio responded with a [503] status code.', $props['apiError']);
    }

    #[Test]
    public function it_renders_an_api_error_when_twilio_cant_be_reached()
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host.'));

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }

    private function form(?array $configs = null): FormContract
    {
        $form = Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => [
                ['handle' => 'phone', 'field' => ['type' => 'phone']],
                ['handle' => 'enquiry_type', 'field' => ['type' => 'text']],
            ],
        ]);

        if ($configs) {
            $form->connections(['twilio' => $configs]);
        }

        return tap($form)->save();
    }
}
