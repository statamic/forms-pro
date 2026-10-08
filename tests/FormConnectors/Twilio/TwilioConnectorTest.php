<?php

namespace Tests\FormConnectors\Twilio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\Twilio;
use Statamic\FormsPro\FormConnectors\Twilio\SendTwilioMessage;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class TwilioConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;

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
    public function it_returns_a_job_per_twilio_notification()
    {
        $form = $this->form([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
            ['id' => 'def', 'to' => 'field:phone', 'from' => '+14155552671', 'body' => 'Thanks!'],
        ]);

        $jobs = $this->finalized($form->makeSubmission()->data(['phone' => '07700 900123']));

        $this->assertCount(2, $jobs);
        $this->assertContainsOnlyInstancesOf(SendTwilioMessage::class, $jobs);
        $this->assertEquals('+447700900123', $jobs[0]->config['to']);
        $this->assertEquals('field:phone', $jobs[1]->config['to']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_has_no_twilio_notifications()
    {
        $this->assertEmpty($this->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_skips_disabled_notifications()
    {
        $form = $this->form([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'One', 'enabled' => false],
            ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'Two'],
        ]);

        $jobs = $this->finalized($form->makeSubmission()->data([]));

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

        $this->assertCount(1, $this->finalized($form->makeSubmission()->data(['enquiry_type' => 'sales'])));
        $this->assertCount(0, $this->finalized($form->makeSubmission()->data(['enquiry_type' => 'support'])));
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
        $this->fakeHttp([
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

        $this->assertEquals('twilio-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'defaults', 'isConfigured', 'apiError'], array_keys($component['props']));
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
        config(['statamic.forms-pro.connections.twilio' => null]);

        $this->fakeHttp();

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_only_asks_twilio_for_resources_a_standard_api_key_can_reach()
    {
        $this->fakeHttp([
            'api.twilio.com/*/IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => []]),
            'messaging.twilio.com/v1/Services*' => Http::response(['services' => []]),
        ]);

        (new Twilio)->render($this->form());

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::ACCOUNT_SID.'.json'));
    }

    #[Test]
    public function it_renders_api_errors()
    {
        $this->fakeHttp(['*' => Http::response(['code' => 20003], 401)]);

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertEquals('The [FORMS_PRO_TWILIO_API_KEY] and [FORMS_PRO_TWILIO_API_SECRET] environment variables are invalid.', $props['apiError']);
        $this->assertEquals([], $props['defaults']['meta']['from']['options']);
    }

    #[Test]
    public function it_renders_an_api_error_when_twilio_is_unavailable()
    {
        $this->fakeHttp(['*' => Http::response(null, 503)]);

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertEquals('Twilio responded with a [503] status code.', $props['apiError']);
    }

    #[Test]
    public function it_renders_an_api_error_when_twilio_cant_be_reached()
    {
        $this->fakeHttp(fn () => throw new ConnectionException('Could not resolve host.'));

        $props = (new Twilio)->render($this->form())->toArray()['props'];

        $this->assertStringContainsString('Could not resolve host.', $props['apiError']);
    }

    #[Test]
    public function it_pre_processes_the_configs()
    {
        $form = $this->form([
            [
                'id' => 'one',
                'to' => '+447700900123',
                'from' => '+14155552671',
                'body' => 'First',
                'conditions' => [['field' => 'enquiry_type', 'operator' => 'equals', 'value' => 'sales', 'join' => 'and']],
            ],
            ['id' => 'two', 'to' => 'field:phone', 'from' => '+14155552671', 'body' => 'Second', 'enabled' => false],
        ]);

        $configs = (new Twilio)->preProcess($form->connections()->get('twilio'), $form);

        $this->assertEquals('one', $configs[0]['id']);
        $this->assertTrue($configs[0]['enabled']);
        $this->assertEquals('+447700900123', $configs[0]['to']);
        $this->assertEquals('First', $configs[0]['body']);
        $this->assertNotEmpty($configs[0]['conditions'][0]['_id']);
        $this->assertEquals('two', $configs[1]['id']);
        $this->assertFalse($configs[1]['enabled']);
        $this->assertEquals([], $configs[1]['conditions']);
    }

    #[Test]
    public function it_validates_the_configs()
    {
        $validator = Validator::make([
            [
                'id' => 'abc',
                'to' => '+447700900123',
                'from' => '+14155552671',
                'body' => 'New enquiry',
                'country_code' => '+44',
                'enabled' => true,
                'conditions' => [['field' => 'enquiry_type', 'operator' => 'equals', 'value' => 'sales', 'join' => 'and']],
            ],
        ], (new Twilio)->rules($this->form()));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs($configs, $errors)
    {
        $validator = Validator::make($configs, (new Twilio)->rules($this->form()));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        $valid = ['to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'];

        return [
            'config not an array' => [['nope'], ['0']],
            'missing recipient' => [[array_diff_key($valid, ['to' => ''])], ['0.to']],
            'recipient without a country code' => [[[...$valid, 'to' => '07700900123']], ['0.to']],
            'recipient naming an unknown field' => [[[...$valid, 'to' => 'field:nope']], ['0.to']],
            'missing sender' => [[array_diff_key($valid, ['from' => ''])], ['0.from']],
            'sender that isnt a number or service' => [[[...$valid, 'from' => 'STATAMIC']], ['0.from']],
            'missing body' => [[array_diff_key($valid, ['body' => ''])], ['0.body']],
            'body over the segment limit' => [[[...$valid, 'body' => str_repeat('a', 1601)]], ['0.body']],
            'dialling code without a plus' => [[[...$valid, 'country_code' => '44']], ['0.country_code']],
        ];
    }

    #[Test]
    public function it_processes_the_configs()
    {
        $form = $this->form();

        $this->assertEquals([
            ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'New', 'enabled' => false, 'conditions' => [
                ['field' => 'enquiry_type', 'operator' => 'equals', 'value' => 'sales', 'join' => 'and'],
            ]],
            ['id' => 'ghi', 'to' => 'field:phone', 'from' => 'MG0123456789abcdef0123456789abcdef', 'body' => 'Thanks!', 'country_code' => '+44'],
        ], (new Twilio)->process([
            ['id' => 'def', 'to' => '+447700900124', 'from' => '+14155552671', 'body' => 'New', 'enabled' => false, 'conditions' => [
                ['_id' => 'vue-row', 'field' => 'enquiry_type', 'operator' => 'equals', 'value' => 'sales', 'join' => 'and'],
                ['field' => null, 'operator' => 'equals', 'value' => 'incomplete', 'join' => 'and'],
            ]],
            ['id' => 'ghi', 'to' => 'field:phone', 'from' => 'MG0123456789abcdef0123456789abcdef', 'body' => 'Thanks!', 'country_code' => '+44', 'enabled' => true],
        ], $form));
    }

    #[Test]
    public function it_doesnt_persist_the_client_row_state()
    {
        $form = $this->form();

        $this->assertEquals([
            ['id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
        ], (new Twilio)->process([
            ['_id' => 'vue-row', 'id' => 'abc', 'to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
        ], $form));
    }

    #[Test]
    public function it_generates_an_id_for_configs_that_dont_have_one()
    {
        $config = (new Twilio)->process([
            ['to' => '+447700900123', 'from' => '+14155552671', 'body' => 'New enquiry'],
        ], $this->form())[0];

        $this->assertNotEmpty($config['id']);
        $this->assertEquals('+447700900123', $config['to']);
    }

    #[Test]
    public function it_removes_null_values_from_configs()
    {
        $this->assertEquals([[
            'id' => 'abc',
            'to' => '+447700900123',
            'from' => '+14155552671',
            'body' => 'New enquiry',
        ]], (new Twilio)->process([[
            'id' => 'abc',
            'to' => '+447700900123',
            'from' => '+14155552671',
            'body' => 'New enquiry',
            'country_code' => null,
            'enabled' => null,
        ]], $this->form()));
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

    private function finalized(Submission $submission): array
    {
        return (new Twilio)->setConfig($submission->form()->connections()->get(Twilio::handle(), []))->finalized($submission);
    }
}
