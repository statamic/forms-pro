<?php

namespace Tests\AutomagicForms;

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form;
use Statamic\Facades;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class AutomagicFormTurnstileTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();

        config([
            'forms-pro.turnstile.site_key' => 'site-key',
            'forms-pro.turnstile.secret_key' => 'secret-key',
        ]);
    }

    #[Test]
    public function the_form_page_includes_the_site_key_when_turnstile_is_configured()
    {
        $this->createForm();

        $this
            ->inertia(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('props.turnstileSiteKey', 'site-key');
    }

    #[Test]
    public function the_form_page_includes_no_site_key_when_turnstile_is_not_configured()
    {
        config(['forms-pro.turnstile.secret_key' => null]);

        $this->createForm();

        $this
            ->inertia(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('props.turnstileSiteKey', null);
    }

    #[Test]
    public function submission_is_created_when_verification_passes()
    {
        $form = $this->createForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']));

        $this->assertCount(1, $form->submissions());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request['secret'] === 'secret-key'
                && $request['response'] === 'a-token';
        });
    }

    #[Test]
    public function submission_fails_validation_when_the_token_is_missing()
    {
        $form = $this->createForm();

        Http::fake();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cf-turnstile-response');

        $this->assertCount(0, $form->submissions());

        Http::assertNothingSent();
    }

    #[Test]
    public function submission_fails_validation_when_verification_fails()
    {
        $form = $this->createForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cf-turnstile-response');

        $this->assertCount(0, $form->submissions());
    }

    #[Test]
    public function token_is_only_verified_when_a_multi_page_form_is_finalized()
    {
        $form = $this->createMultiPageForm();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_1']), [
                'name' => 'Han Solo',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']));

        Http::assertNothingSent();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_2']), [
                'email' => 'han@example.com',
                'cf-turnstile-response' => 'a-token',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']));

        Http::assertSentCount(1);

        $submission = $form->submissions()->first();

        $this->assertNotNull($submission);
        $this->assertFalse($submission->isPartial());
        $this->assertEquals('Han Solo', $submission->get('name'));
        $this->assertEquals('han@example.com', $submission->get('email'));
    }

    private function inertia(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true']);
    }

    private function createForm(): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'fields' => [
                    ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => ['required']]],
                    ['handle' => 'email', 'field' => ['type' => 'text', 'validate' => ['required', 'email']]],
                ],
            ]))->save();
    }

    private function createMultiPageForm(): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'pages' => [
                    ['id' => 'page_1', 'sections' => [['fields' => [
                        ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => ['required']]],
                    ]]]],
                    ['id' => 'page_2', 'sections' => [['fields' => [
                        ['handle' => 'email', 'field' => ['type' => 'text', 'validate' => ['required', 'email']]],
                    ]]]],
                ],
            ]))->save();
    }
}
