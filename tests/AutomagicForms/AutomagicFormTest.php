<?php

namespace Tests\AutomagicForms;

use Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form;
use Statamic\Events\SubmissionFinalized;
use Statamic\Facades;
use Statamic\Facades\Addon;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class AutomagicFormTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();
    }

    #[Test]
    public function it_renders_the_form_page()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms-pro::AutomagicForm/Index')
                ->where('title', 'Contact')
                ->where('currentPage.id', 'main')
                ->where('action', route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']))
                ->where('previousPageUrl', null));
    }

    #[Test]
    public function it_generates_urls_using_the_configured_route()
    {
        $this->createForm();

        $this->assertEquals(
            'http://localhost/forms/contact',
            route('statamic.forms-pro.form.default.index', ['form' => 'contact'])
        );
    }

    #[Test]
    public function it_404s_when_automagic_forms_is_disabled()
    {
        config()->set('forms-pro.automagic_forms.enabled', false);

        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_form_does_not_exist()
    {
        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'unknown']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_page_does_not_exist()
    {
        $this->createMultiPageForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_99']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_form_doesnt_have_automagic_enabled()
    {
        $this->createForm(automagic: false);

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertNotFound();
    }

    #[Test]
    public function it_renders_with_appearance_settings()
    {
        $this->createForm();

        Addon::get('statamic/forms-pro')->settings()->set(['brand_color' => '#bada55', 'background' => 'bamboo'])->save();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.brandColor', '#bada55')
                ->where('settings.background', 'bamboo')
                ->where('settings.logoUrl', null));
    }

    #[Test]
    public function it_renders_existing_values_from_a_partial_submission()
    {
        $form = $this->createForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'Han Solo', 'email' => 'han@example.com'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('values.name', 'Han Solo')
                ->where('values.email', 'han@example.com'));
    }

    #[Test]
    public function it_preprocesses_existing_values_from_a_partial_submission()
    {
        $form = tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'fields' => [
                    ['handle' => 'when', 'field' => ['type' => 'date']],
                ],
            ]))->save();

        $submission = $form->makeSubmission();
        $submission->data(['when' => '2026-07-20'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('values.when', '2026-07-20T00:00:00.000Z'));
    }

    #[Test]
    public function it_renders_a_later_page_with_a_link_back_to_the_previous_one()
    {
        $form = $this->createMultiPageForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'Han Solo'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentPage.id', 'page_2')
                ->where('previousPageUrl', route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_1'])));
    }

    #[Test]
    public function it_links_back_to_the_page_the_logic_routed_from_rather_than_the_previous_one()
    {
        $form = $this->createBranchingForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'skip'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_3']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('previousPageUrl', route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_1'])));
    }

    #[Test]
    public function it_does_not_link_back_from_a_page_the_page_logic_skips_over()
    {
        $form = $this->createBranchingForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'skip'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('previousPageUrl', null));
    }

    #[Test]
    public function it_redirects_to_the_first_page_when_visiting_a_later_page_without_a_partial_submission()
    {
        $this->createMultiPageForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertRedirect(route('statamic.forms-pro.form.default.index', ['form' => 'contact']));
    }

    #[Test]
    public function it_renders_the_first_page_without_a_partial_submission()
    {
        $this->createMultiPageForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('currentPage.id', 'page_1'));
    }

    #[Test]
    public function it_renders_a_single_page_form_without_a_partial_submission()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'main']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('currentPage.id', 'main'));
    }

    #[Test]
    #[DataProvider('progressProvider')]
    public function it_includes_the_progress_through_the_pages(string $page, int $progress)
    {
        $form = $this->createThreePageForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'Han Solo'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => $page]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('progress', $progress));
    }

    public static function progressProvider(): array
    {
        return [
            'first page' => ['page_1', 25],
            'middle page' => ['page_2', 50],
            'last page' => ['page_3', 75],
        ];
    }

    #[Test]
    #[DataProvider('branchingProgressProvider')]
    public function it_bases_the_progress_on_the_pages_the_page_logic_will_route_to(string $page, int $progress)
    {
        $form = $this->createBranchingForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'skip'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => $page]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('progress', $progress));
    }

    public static function branchingProgressProvider(): array
    {
        return [
            'first page of two' => ['page_1', 33],
            'last page of two' => ['page_3', 67],
        ];
    }

    #[Test]
    public function it_does_not_include_any_progress_for_a_single_page_form()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('progress', null));
    }

    #[Test]
    public function it_does_not_include_any_progress_for_a_page_the_page_logic_skips_over()
    {
        $form = $this->createBranchingForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'skip'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('progress', null));
    }

    #[Test]
    public function it_redirects_to_the_restricted_page_when_the_form_is_restricted()
    {
        $this->createForm()->set('require_login', true)->save();

        $this
            ->get(route('statamic.forms-pro.form.default.index', ['form' => 'contact']))
            ->assertRedirect(route('statamic.forms-pro.form.default.restricted', ['form' => 'contact']));
    }

    #[Test]
    public function it_renders_the_restriction_message()
    {
        $this->createForm()->set('require_login', true)->save();

        $this
            ->get(route('statamic.forms-pro.form.default.restricted', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms-pro::AutomagicForm/Restricted')
                ->where('title', 'Contact')
                ->where('message', 'You must be logged in to submit this form.'));
    }

    #[Test]
    public function it_renders_a_custom_restriction_message()
    {
        $this->createForm()
            ->set('require_login', true)
            ->set('require_login_message', 'Please sign in first.')
            ->save();

        $this
            ->get(route('statamic.forms-pro.form.default.restricted', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('message', 'Please sign in first.'));
    }

    #[Test]
    public function it_redirects_to_the_form_when_the_restricted_page_is_visited_and_the_form_is_not_restricted()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.restricted', ['form' => 'contact']))
            ->assertRedirect(route('statamic.forms-pro.form.default.index', ['form' => 'contact']));
    }

    #[Test]
    public function it_forbids_submitting_a_restricted_form()
    {
        $form = $this->createForm();
        $form->set('require_login', true)->save();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertForbidden()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.restricted', ['form' => 'contact']));

        $this->assertNull($form->submissions()->first());
    }

    #[Test]
    public function it_submits_the_form()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true);

        $submission = $form->submissions()->first();

        $this->assertNotNull($submission);
        $this->assertFalse($submission->isPartial());
        $this->assertEquals('Han Solo', $submission->get('name'));
        $this->assertEquals('han@example.com', $submission->get('email'));
    }

    #[Test]
    public function it_pretends_the_submission_succeeded_when_it_silently_fails()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
                'honeypot' => 'gotcha',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true);

        $this->assertCount(0, $form->submissions());
    }

    #[Test]
    public function it_forgets_the_partial_submission_when_the_final_page_silently_fails()
    {
        $form = $this->createMultiPageForm();

        $this->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_1']), [
            'name' => 'Han Solo',
        ]);

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_2']), [
                'email' => 'han@example.com',
                'honeypot' => 'gotcha',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true)
            ->assertSessionMissing('form.contact.partial_submission');

        $this->assertNull($form->submissions()->first(fn ($submission) => ! $submission->isPartial()));
    }

    #[Test]
    public function it_returns_validation_errors_when_the_submission_is_invalid()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => '',
                'email' => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->assertCount(0, $form->submissions());
    }

    #[Test]
    public function it_validates_a_precognitive_request_without_submitting()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ], ['Precognition' => 'true'])
            ->assertNoContent()
            ->assertHeader('Precognition-Success', 'true');

        $this->assertCount(0, $form->submissions());
    }

    #[Test]
    public function it_returns_validation_errors_for_a_precognitive_request()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => '',
                'email' => 'not-an-email',
            ], ['Precognition' => 'true'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);

        $this->assertCount(0, $form->submissions());
    }

    #[Test]
    public function it_only_validates_the_requested_fields_for_a_precognitive_request()
    {
        $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => '',
                'email' => 'not-an-email',
            ], ['Precognition' => 'true', 'Precognition-Validate-Only' => 'email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingValidationErrors('name');
    }

    #[Test]
    public function it_advances_to_the_next_page_without_finalizing()
    {
        $form = $this->createMultiPageForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_1']), [
                'name' => 'Han Solo',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.index', ['form' => 'contact', 'page' => 'page_2']));

        $submission = $form->submissions()->first();

        $this->assertNotNull($submission);
        $this->assertTrue($submission->isPartial());
    }

    #[Test]
    public function it_finalizes_when_submitting_the_last_page()
    {
        Event::fake();

        $form = $this->createMultiPageForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'Han Solo'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_2']), [
                'email' => 'han@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true);

        $submission = $form->submissions()->first();

        $this->assertFalse($submission->isPartial());
        $this->assertEquals('Han Solo', $submission->get('name'));
        $this->assertEquals('han@example.com', $submission->get('email'));

        Event::assertDispatched(SubmissionFinalized::class);
    }

    #[Test]
    public function it_404s_when_submitting_a_form_when_automagic_forms_is_disabled()
    {
        config()->set('forms-pro.automagic_forms.enabled', false);

        $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_submitting_a_form_that_does_not_exist()
    {
        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'unknown', 'page' => 'main']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_submitting_a_page_that_does_not_exist()
    {
        $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'page_99']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_submitting_a_form_that_doesnt_have_automagic_enabled()
    {
        $this->createForm(automagic: false);

        $this
            ->postJson(route('statamic.forms-pro.form.default.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertNotFound();
    }

    #[Test]
    public function it_renders_the_success_page_after_submitting()
    {
        $form = $this->createForm();

        $form
            ->set('automagic_form_success_heading', 'Nice one!')
            ->set('automagic_form_success_message', 'We got your submission.')
            ->set('automagic_form_show_reset', true)
            ->set('automagic_form_reset_label', 'Start over')
            ->save();

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('forms-pro::AutomagicForm/Success')
                ->where('title', 'Contact')
                ->where('heading', 'Nice one!')
                ->where('message', 'We got your submission.')
                ->where('showReset', true)
                ->where('resetLabel', 'Start over')
                ->where('resetUrl', route('statamic.forms-pro.form.default.index', ['form' => 'contact'])));
    }

    #[Test]
    public function it_falls_back_to_default_copy_when_not_configured()
    {
        $this->createForm();

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('heading', 'Thank you!')
                ->where('message', 'Your submission has been received.')
                ->where('showReset', false)
                ->where('resetLabel', 'Fill out the form again'));
    }

    #[Test]
    public function it_redirects_to_the_form_when_the_success_page_is_visited_without_submitting()
    {
        $this->createForm();

        $this
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertRedirect(route('statamic.forms-pro.form.default.index', ['form' => 'contact']));
    }

    #[Test]
    public function it_404s_on_the_success_page_when_automagic_forms_is_disabled()
    {
        config()->set('forms-pro.automagic_forms.enabled', false);

        $this->createForm();

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_success_page_form_does_not_exist()
    {
        $this
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'unknown']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_success_page_form_doesnt_have_automagic_enabled()
    {
        $this->createForm(automagic: false);

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->get(route('statamic.forms-pro.form.default.success', ['form' => 'contact']))
            ->assertNotFound();
    }

    private function createForm(bool $automagic = true): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', $automagic)
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

    private function createThreePageForm(): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'pages' => [
                    ['id' => 'page_1', 'sections' => [['fields' => [
                        ['handle' => 'name', 'field' => ['type' => 'text']],
                    ]]]],
                    ['id' => 'page_2', 'sections' => [['fields' => [
                        ['handle' => 'email', 'field' => ['type' => 'text']],
                    ]]]],
                    ['id' => 'page_3', 'sections' => [['fields' => [
                        ['handle' => 'message', 'field' => ['type' => 'text']],
                    ]]]],
                ],
            ]))->save();
    }

    private function createBranchingForm(): Form
    {
        return tap(Facades\Form::make('contact')
            ->title('Contact')
            ->set('automagic_form', true)
            ->formFields([
                'pages' => [
                    [
                        'id' => 'page_1',
                        'rules' => [[
                            'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'skip']],
                            'destination' => 'page_3',
                        ]],
                        'sections' => [['fields' => [
                            ['handle' => 'name', 'field' => ['type' => 'text']],
                        ]]],
                    ],
                    ['id' => 'page_2', 'sections' => [['fields' => [
                        ['handle' => 'email', 'field' => ['type' => 'text']],
                    ]]]],
                    ['id' => 'page_3', 'sections' => [['fields' => [
                        ['handle' => 'message', 'field' => ['type' => 'text']],
                    ]]]],
                ],
            ]))->save();
    }
}
