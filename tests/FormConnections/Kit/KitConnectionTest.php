<?php

namespace Tests\FormConnections\Kit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\Kit;
use Statamic\FormsPro\FormConnections\Kit\SubscribeToKit;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class KitConnectionTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    private const int FORM = 9785233;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.kit.api_key' => 'kit_apikey']);
    }

    #[Test]
    public function it_returns_a_job_when_an_email_field_is_configured()
    {
        $form = $this->form(['email_field' => 'email']);

        $jobs = (new Kit)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(SubscribeToKit::class, $jobs);
        $this->assertEquals('email', $jobs[0]->config['email_field']);
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected_to_kit()
    {
        $this->assertEmpty((new Kit)->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_email_field_is_missing()
    {
        $form = $this->form(['form' => self::FORM]);

        $this->assertEmpty((new Kit)->finalized($form->makeSubmission()->data(['email' => 'jack@example.com'])));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_submission_has_no_email_address()
    {
        $form = $this->form(['email_field' => 'email']);

        $this->assertEmpty((new Kit)->finalized($form->makeSubmission()->data(['email' => ''])));
    }

    #[Test]
    #[DataProvider('consentProvider')]
    public function it_only_subscribes_when_consent_has_been_given($value, bool $shouldSubscribe)
    {
        $form = $this->form(['email_field' => 'email', 'consent_field' => 'consent']);

        $jobs = (new Kit)->finalized($form->makeSubmission()->data([
            'email' => 'jack@example.com',
            'consent' => $value,
        ]));

        $this->assertCount($shouldSubscribe ? 1 : 0, $jobs);
    }

    public static function consentProvider(): array
    {
        return [
            'checked toggle' => [true, true],
            'unchecked toggle' => [false, false],
            'missing value' => [null, false],
            'empty string' => ['', false],
            'checked checkbox' => [['newsletter'], true],
            'unchecked checkbox' => [[], false],
            'yes' => ['yes', true],
            'no' => ['no', false],
            'zero' => ['0', false],
            'one' => ['1', true],
        ];
    }

    #[Test]
    public function it_renders_the_connection_with_the_forms_tags_and_custom_fields()
    {
        $this->fakeAccount();

        $props = (new Kit)->render($this->form(['form' => self::FORM, 'email_field' => 'email']))->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['apiError']);
        $this->assertEquals(self::FORM, $props['values']['form']);

        $this->assertEquals([
            ['value' => (string) self::FORM, 'label' => 'Newsletter'],
            ['value' => '9776140', 'label' => 'Newsletter site'],
        ], $props['meta']['form']['options']);

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_follows_pagination_when_listing_tags()
    {
        Http::fake([
            'api.kit.com/v4/forms*' => Http::response(['forms' => []]),
            'api.kit.com/v4/custom_fields*' => Http::response(['custom_fields' => []]),
            'api.kit.com/v4/tags*after=page-two*' => Http::response(['tags' => [
                ['id' => 22367469, 'name' => 'Subscribers'],
            ]]),
            'api.kit.com/v4/tags*' => Http::response([
                'tags' => [['id' => 22367468, 'name' => 'Customers']],
                'pagination' => ['end_cursor' => 'page-two', 'has_next_page' => true],
            ]),
        ]);

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertEquals([
            ['value' => '22367468', 'label' => 'Customers'],
            ['value' => '22367469', 'label' => 'Subscribers'],
        ], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['forms-pro.connections.kit.api_key' => null]);

        Http::fake();

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_api_errors()
    {
        Http::fake(['api.kit.com/*' => Http::response(null, 401)]);

        $props = (new Kit)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertStringContainsString('FORMS_PRO_KIT_API_KEY', $props['apiError']);
        $this->assertEquals([], $props['meta']['form']['options']);
        $this->assertEquals([], $props['meta']['tags']['options']);
    }

    #[Test]
    public function it_scopes_the_cache_key_to_the_api_key()
    {
        $this->fakeAccount();

        (new Kit)->render($this->form());

        config(['forms-pro.connections.kit.api_key' => 'kit_different']);

        (new Kit)->render($this->form());

        Http::assertSentCount(6);
    }

    private function fakeAccount(): void
    {
        Http::fake([
            'api.kit.com/v4/forms*' => Http::response(['forms' => [
                ['id' => self::FORM, 'name' => 'Newsletter'],
                ['id' => 9776140, 'name' => 'Newsletter site'],
            ]]),
            'api.kit.com/v4/tags*' => Http::response(['tags' => [
                ['id' => 22367469, 'name' => 'Subscribers'],
                ['id' => 22367468, 'name' => 'Customers'],
            ]]),
            'api.kit.com/v4/custom_fields*' => Http::response(['custom_fields' => [
                ['id' => 1335444, 'key' => 'last_name', 'label' => 'Last name'],
            ]]),
        ]);
    }

    private function form(?array $config = null): FormContract
    {
        $form = Form::make('test')->formFields([
            'fields' => [
                ['handle' => 'email', 'field' => ['type' => 'text']],
                ['handle' => 'consent', 'field' => ['type' => 'toggle']],
            ],
        ]);

        if ($config !== null) {
            $form->connections(['kit' => $config]);
        }

        return tap($form)->save();
    }
}
