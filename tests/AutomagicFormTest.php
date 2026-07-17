<?php

namespace Tests;

use Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form;
use Statamic\Events\SubmissionFinalized;
use Statamic\Facades;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class AutomagicFormTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Facades\Form::all()->each->delete();

        $this->actingAs(User::make()->save());
    }

    #[Test]
    public function it_renders_the_form_page()
    {
        $this->createForm();

        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('component', 'forms-pro::AutomagicForm/Index')
            ->assertJsonPath('props.title', 'Contact')
            ->assertJsonPath('props.currentPage.id', 'main')
            ->assertJsonPath('props.action', route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']))
            ->assertJsonPath('props.previousPageUrl', null);
    }

    #[Test]
    public function it_404s_when_the_form_does_not_exist()
    {
        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'unknown']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_page_does_not_exist()
    {
        $this->createMultiPageForm();

        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => 'page_99']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_form_is_not_an_automagic_form()
    {
        $this->createForm(automagic: false);

        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact']))
            ->assertNotFound();
    }

    #[Test]
    public function it_renders_existing_values_from_a_partial_submission()
    {
        $form = $this->createForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'Han Solo', 'email' => 'han@example.com'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('props.values.name', 'Han Solo')
            ->assertJsonPath('props.values.email', 'han@example.com');
    }

    #[Test]
    public function it_renders_a_later_page_with_a_link_back_to_the_previous_one()
    {
        $this->createMultiPageForm();

        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertOk()
            ->assertJsonPath('props.currentPage.id', 'page_2')
            ->assertJsonPath('props.previousPageUrl', route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => 'page_1']));
    }

    #[Test]
    #[DataProvider('progressProvider')]
    public function it_includes_the_progress_through_the_pages(string $page, int $progress)
    {
        $this->createThreePageForm();

        $this
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => $page]))
            ->assertOk()
            ->assertJsonPath('props.progress', $progress);
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
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => $page]))
            ->assertOk()
            ->assertJsonPath('props.progress', $progress);
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
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('props.progress', null);
    }

    #[Test]
    public function it_does_not_include_any_progress_for_a_page_the_page_logic_skips_over()
    {
        $form = $this->createBranchingForm();

        $submission = $form->makeSubmission();
        $submission->data(['name' => 'skip'])->asPartial()->save();

        $this
            ->withSession(["form.{$form->handle()}.partial_submission" => $submission->id()])
            ->inertia(route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => 'page_2']))
            ->assertOk()
            ->assertJsonPath('props.progress', null);
    }

    #[Test]
    public function it_submits_the_form()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
                'name' => 'Han Solo',
                'email' => 'han@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true);

        $submission = $form->submissions()->first();

        $this->assertNotNull($submission);
        $this->assertFalse($submission->isPartial());
        $this->assertEquals('Han Solo', $submission->get('name'));
        $this->assertEquals('han@example.com', $submission->get('email'));
    }

    #[Test]
    public function it_returns_validation_errors_when_the_submission_is_invalid()
    {
        $form = $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
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
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
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
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
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
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
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
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'page_1']), [
                'name' => 'Han Solo',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.index', ['form' => 'contact', 'page' => 'page_2']));

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
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'page_2']), [
                'email' => 'han@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('redirect', route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertSessionHas('automagic_form_submitted', true);

        $submission = $form->submissions()->first();

        $this->assertFalse($submission->isPartial());
        $this->assertEquals('Han Solo', $submission->get('name'));
        $this->assertEquals('han@example.com', $submission->get('email'));

        Event::assertDispatched(SubmissionFinalized::class);
    }

    #[Test]
    public function it_404s_when_submitting_a_form_that_does_not_exist()
    {
        $this
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'unknown', 'page' => 'main']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_submitting_a_page_that_does_not_exist()
    {
        $this->createForm();

        $this
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'page_99']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_submitting_a_form_that_is_not_an_automagic_form()
    {
        $this->createForm(automagic: false);

        $this
            ->postJson(route('statamic.forms-pro.form.store', ['form' => 'contact', 'page' => 'main']), [
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
            ->inertia(route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('component', 'forms-pro::AutomagicForm/Success')
            ->assertJsonPath('props.title', 'Contact')
            ->assertJsonPath('props.heading', 'Nice one!')
            ->assertJsonPath('props.message', 'We got your submission.')
            ->assertJsonPath('props.showReset', true)
            ->assertJsonPath('props.resetLabel', 'Start over')
            ->assertJsonPath('props.resetUrl', route('statamic.forms-pro.form.index', ['form' => 'contact']));
    }

    #[Test]
    public function it_falls_back_to_default_copy_when_not_configured()
    {
        $this->createForm();

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->inertia(route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertOk()
            ->assertJsonPath('props.heading', 'Thank you!')
            ->assertJsonPath('props.message', 'Your submission has been received.')
            ->assertJsonPath('props.showReset', false)
            ->assertJsonPath('props.resetLabel', 'Fill out the form again');
    }

    #[Test]
    public function it_redirects_to_the_form_when_the_success_page_is_visited_without_submitting()
    {
        $this->createForm();

        $this
            ->inertia(route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertRedirect(route('statamic.forms-pro.form.index', ['form' => 'contact']));
    }

    #[Test]
    public function it_404s_when_the_success_page_form_does_not_exist()
    {
        $this
            ->inertia(route('statamic.forms-pro.form.success', ['form' => 'unknown']))
            ->assertNotFound();
    }

    #[Test]
    public function it_404s_when_the_success_page_is_not_an_automagic_form()
    {
        $this->createForm(automagic: false);

        $this
            ->withSession(['automagic_form_submitted' => true])
            ->inertia(route('statamic.forms-pro.form.success', ['form' => 'contact']))
            ->assertNotFound();
    }

    private function inertia(string $url): TestResponse
    {
        return $this->get($url, ['X-Inertia' => 'true']);
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
