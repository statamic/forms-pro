<?php

namespace Statamic\FormsPro\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades;
use Statamic\Fields\Tab;
use Statamic\Forms\SubmitForm;
use Statamic\Http\Controllers\Controller;
use Statamic\Support\Arr;
use Statamic\Support\Str;

use function Statamic\trans as __;

class AutomagicFormController extends Controller
{
    public function index(Request $request, string $form, ?string $page = null)
    {
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($currentPage = $this->currentPage($form, $page), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        $currentPageId = Arr::get($currentPage, 'id');
        $previousPage = $this->previousPage($form, $currentPageId);

        $blueprint = $form->blueprint();
        $fields = $blueprint->fields()->preProcess();
        $formPages = $form->formFields()->pages()->keyBy(fn (array $page) => $page['id'] ?? 'main');

        if ($partialSubmission = $this->getPartialSubmission($form)) {
            $fields = $fields->addValues($partialSubmission->data()->all());
        }

        $pages = $form->blueprint()->tabs()->map(fn (Tab $tab) => [
            'id' => $tab->handle(),
            'display' => $this->customTabDisplay($tab, $formPages->get($tab->handle(), [])),
            'button_label' => $tab->contents()['button_label'] ?? null,
            'previous_page_label' => $tab->contents()['previous_page_label'] ?? null,
        ])->values();

        return Inertia::render('forms-pro::AutomagicForm', [
            'action' => route('statamic.forms-pro.form.store', ['form' => $form->handle(), 'page' => $currentPageId]),
            'previousPageUrl' => $previousPage ? route('statamic.forms-pro.form.index', ['form' => $form->handle(), 'page' => $previousPage['id']]) : null,
            'title' => $form->title(),
            'pages' => $pages,
            'currentPage' => $pages->where('id', $currentPageId)->first(),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
        ]);
    }

    public function store(Request $request, string $form, string $page, SubmitForm $action)
    {
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($currentPage = $this->currentPage($form, $page), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        $action->form($form);
        $action->page(Arr::get($currentPage, 'id'));

        if ($form->hasMultiplePages() && $partialSubmission = $this->getPartialSubmission($form)) {
            $action->resume($partialSubmission);
        }

        if ($request->isPrecognitive()) {
            $action->validate($request->all(), $request->allFiles(), only: $this->precognitiveFields($request));

            return response()->noContent(headers: ['Precognition-Success' => 'true']);
        }

        $result = $action->submit($request->all(), $request->allFiles());

        $result->isFinalized()
            ? $this->forgetPartialSubmission($form)
            : $this->setPartialSubmission($form, $result->submission);

        if ($result->isFinalized()) {
            $request->session()->flash('automagic_form_submitted', true);

            return [
                'redirect' => route('statamic.forms-pro.form.success', ['form' => $form->handle()]),
            ];
        }

        return [
            'redirect' => route('statamic.forms-pro.form.index', [
                'form' => $form->handle(),
                'page' => $result->nextPage,
            ]),
        ];
    }

    public function submitted(Request $request, string $form)
    {
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        if (! $request->session()->get('automagic_form_submitted')) {
            return redirect()->route('statamic.forms-pro.form.index', ['form' => $form->handle()]);
        }

        return Inertia::render('forms-pro::AutomagicFormSuccess', [
            'title' => $form->title(),
            'heading' => $form->get('automagic_form_success_heading', __('forms-pro::messages.automagic_form_success_heading_default')),
            'message' => $form->get('automagic_form_success_message', __('forms-pro::messages.automagic_form_success_message_default')),
            'showReset' => (bool) $form->get('automagic_form_show_reset'),
            'resetLabel' => $form->get('automagic_form_reset_label', __('forms-pro::messages.automagic_form_reset_label_default')),
            'resetUrl' => route('statamic.forms-pro.form.index', ['form' => $form->handle()]),
        ]);
    }

    private function precognitiveFields(Request $request): ?array
    {
        if (! $request->headers->has('Precognition-Validate-Only')) {
            return null;
        }

        return explode(',', $request->header('Precognition-Validate-Only'));
    }

    private function currentPage(Form $form, ?string $page = null): ?array
    {
        $pages = $form->formFields()->pages();

        if ($page) {
            return $pages->where('id', $page)->first();
        }

        return $pages->first();
    }

    private function previousPage(Form $form, string $currentPage): ?array
    {
        $pages = $form->formFields()->pages();

        if ($pages->first()['id'] === $currentPage) {
            return null;
        }

        $previousPageIndex = $pages->search(fn ($page) => $page['id'] === $currentPage) - 1;

        return $pages->get($previousPageIndex);
    }

    /**
     * Only return a page title when the editor has set a custom label.
     *
     * Pages without a display in the form YAML get a default "Page X of Y"
     * label when the blueprint is built. Tab handles like "page_1_of_4" can
     * also be humanized to the same text, so we ignore those defaults too.
     */
    private function customTabDisplay(Tab $tab, array $page): ?string
    {
        if (! array_key_exists('display', $page)) {
            return null;
        }

        $display = Arr::get($tab->contents(), 'display');

        if (! $display) {
            return null;
        }

        $defaultDisplay = __(Str::humanize($tab->handle()));

        return $display !== $defaultDisplay ? $display : null;
    }

    private function setPartialSubmission($form, $submission): void
    {
        session()->put("form.{$form->handle()}.partial_submission", $submission->id());
    }

    private function getPartialSubmission($form): ?Submission
    {
        $id = session()->get("form.{$form->handle()}.partial_submission");
        $submission = $form->submission($id);

        if ($submission && ! $submission->isPartial()) {
            return null;
        }

        return $submission;
    }

    private function forgetPartialSubmission($form): void
    {
        session()->forget("form.{$form->handle()}.partial_submission");
    }
}
