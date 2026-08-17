<?php

namespace Tests\Turnstile;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Form;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class VerifyTurnstileTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);
    }

    #[Test]
    public function submission_is_created_when_verification_passes()
    {
        $this->createForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this
            ->post('/!/forms/contact', [
                'email' => 'gandalf@shire.me',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertSessionHasNoErrors()
            ->assertLocation('/');

        $this->assertCount(1, Form::find('contact')->submissions());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'secret-key'
                && $request['response'] === 'a-token';
        });
    }

    #[Test]
    #[DataProvider('incompleteConfigurations')]
    public function verification_is_skipped_until_both_keys_are_configured(?string $siteKey, ?string $secretKey)
    {
        $this->createForm();

        config([
            'forms-pro.turnstile.site_key' => $siteKey,
            'forms-pro.turnstile.secret_key' => $secretKey,
        ]);

        Http::fake();

        $this
            ->post('/!/forms/contact', ['email' => 'gandalf@shire.me'])
            ->assertSessionHasNoErrors()
            ->assertLocation('/');

        $this->assertCount(1, Form::find('contact')->submissions());

        Http::assertNothingSent();
    }

    public static function incompleteConfigurations(): array
    {
        return [
            'no keys' => [null, null],
            'site key only' => ['site-key', null],
            'secret key only' => [null, 'secret-key'],
        ];
    }

    #[Test]
    public function submission_fails_validation_when_the_token_is_missing()
    {
        $this->createForm();

        Http::fake();

        $this
            ->post('/!/forms/contact', ['email' => 'gandalf@shire.me'])
            ->assertSessionHasErrorsIn('form.contact', 'cf-turnstile-response');

        $this->assertCount(0, Form::find('contact')->submissions());

        Http::assertNothingSent();
    }

    #[Test]
    public function submission_fails_validation_when_verification_fails()
    {
        $this->createForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this
            ->post('/!/forms/contact', [
                'email' => 'gandalf@shire.me',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertSessionHasErrorsIn('form.contact', 'cf-turnstile-response');

        $this->assertCount(0, Form::find('contact')->submissions());
    }

    #[Test]
    public function submission_fails_validation_when_cloudflare_is_unreachable()
    {
        $this->createForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::failedConnection()]);

        $this
            ->post('/!/forms/contact', [
                'email' => 'gandalf@shire.me',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertSessionHasErrorsIn('form.contact', 'cf-turnstile-response');

        $this->assertCount(0, Form::find('contact')->submissions());
    }

    #[Test]
    public function token_is_only_verified_when_a_multi_page_form_is_finalized()
    {
        $this->createMultiPageForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post('/!/forms/survey', ['_page' => 'page_one', 'name' => 'Gandalf']);

        Http::assertNothingSent();

        $this->post('/!/forms/survey', [
            '_page' => 'page_two',
            'email' => 'gandalf@shire.me',
            'cf-turnstile-response' => 'a-token',
        ]);

        Http::assertSentCount(1);

        $submissions = Form::find('survey')->submissions();
        $this->assertCount(1, $submissions);
        $this->assertEquals('Gandalf', $submissions->first()->get('name'));
        $this->assertEquals('gandalf@shire.me', $submissions->first()->get('email'));
    }

    private function createForm(): void
    {
        $form = Form::make()->handle('contact')->formFields([
            'sections' => [
                ['fields' => [
                    ['handle' => 'email', 'field' => ['type' => 'text']],
                ]],
            ],
        ]);

        Form::shouldReceive('find')->with('contact')->andReturn($form);
        Form::makePartial();
    }

    private function createMultiPageForm(): void
    {
        $form = Form::make()->handle('survey')->formFields([
            'pages' => [
                [
                    'id' => 'page_one',
                    'sections' => [
                        ['fields' => [['handle' => 'name', 'field' => ['type' => 'text']]]],
                    ],
                ],
                [
                    'id' => 'page_two',
                    'sections' => [
                        ['fields' => [['handle' => 'email', 'field' => ['type' => 'text']]]],
                    ],
                ],
            ],
        ]);

        Form::shouldReceive('find')->with('survey')->andReturn($form);
        Form::makePartial();

        $form->save();
    }
}
