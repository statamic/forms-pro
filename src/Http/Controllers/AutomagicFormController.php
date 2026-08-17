<?php

namespace Statamic\FormsPro\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Exceptions\SilentFormFailureException;
use Statamic\Facades;
use Statamic\Fields\Tab;
use Statamic\Forms\Logic\PageLogic;
use Statamic\Forms\SubmitForm;
use Statamic\FormsPro\AutomagicForms\AutomagicForms;
use Statamic\Http\Controllers\Controller;
use Statamic\Support\Arr;

use function Statamic\trans as __;

class AutomagicFormController extends Controller
{
    public function index(Request $request, string $form, ?string $page = null)
    {
        throw_unless(AutomagicForms::enabled(), NotFoundHttpException::class);
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($currentPage = $this->currentPage($form, $page), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        if ($form->restricted()) {
            return redirect(AutomagicForms::route('restricted', ['form' => $form->handle()]));
        }

        $currentPageId = Arr::get($currentPage, 'id');
        $partialSubmission = $this->getPartialSubmission($form);

        if (! $partialSubmission && $currentPageId !== Arr::get($form->formFields()->pages()->first(), 'id')) {
            return redirect(AutomagicForms::route('index', ['form' => $form->handle()]));
        }

        $blueprint = $form->blueprint();
        $data = $partialSubmission?->data()->all() ?? [];
        $fields = $blueprint->fields()->addValues($data)->preProcess();
        $previousPage = $this->previousPage($form, $currentPageId, $data);
        $formPages = $form->formFields()->pages()->keyBy(fn (array $page) => $page['id'] ?? 'main');

        $tabs = $form->blueprint()->tabs()->values();

        $pages = $tabs->map(fn (Tab $tab, int $index) => [
            'id' => $tab->handle(),
            'display' => $formPages->get($tab->handle(), [])['display'] ?? null,
            'button_label' => $tab->contents()['button_label'] ?? ($index === $tabs->count() - 1 ? __('Submit') : __('Next Page')),
            'previous_page_label' => $this->previousPageLabel($tab->contents()),
        ]);

        return Inertia::render('forms-pro::AutomagicForm/Index', [
            'action' => AutomagicForms::route('store', ['form' => $form->handle(), 'page' => $currentPageId]),
            'previousPageUrl' => $previousPage ? AutomagicForms::route('index', ['form' => $form->handle(), 'page' => $previousPage['id']]) : null,
            'title' => $form->title(),
            'pages' => $pages,
            'currentPage' => $pages->where('id', $currentPageId)->first(),
            'progress' => $this->progress($form, $currentPageId, $data),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'turnstileSiteKey' => config('forms-pro.turnstile.secret_key')
                ? config('forms-pro.turnstile.site_key')
                : null,
        ]);
    }

    public function store(Request $request, string $form, string $page, SubmitForm $action)
    {
        throw_unless(AutomagicForms::enabled(), NotFoundHttpException::class);
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($currentPage = $this->currentPage($form, $page), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        if ($form->restricted()) {
            return response()->json([
                'redirect' => AutomagicForms::route('restricted', ['form' => $form->handle()]),
            ], 403);
        }

        $currentPageId = Arr::get($currentPage, 'id');
        $partialSubmission = $this->getPartialSubmission($form);

        if (! $partialSubmission && $currentPageId !== Arr::get($form->formFields()->pages()->first(), 'id')) {
            return response()->json([
                'redirect' => AutomagicForms::route('index', ['form' => $form->handle()]),
            ], 403);
        }

        $action->form($form);
        $action->page($currentPageId);

        if ($form->hasMultiplePages() && $partialSubmission) {
            $action->resume($partialSubmission);
        }

        if ($request->isPrecognitive()) {
            $action->validate($request->all(), $request->allFiles(), only: $this->precognitiveFields($request));

            return response()->noContent(headers: ['Precognition-Success' => 'true']);
        }

        try {
            $result = $action->submit($request->all(), $request->allFiles());
        } catch (SilentFormFailureException) {
            $this->forgetPartialSubmission($form);

            $request->session()->flash("form.{$form->handle()}.submitted", true);

            return [
                'redirect' => AutomagicForms::route('success', ['form' => $form->handle()]),
            ];
        }

        $result->isFinalized()
            ? $this->forgetPartialSubmission($form)
            : $this->setPartialSubmission($form, $result->submission);

        if ($result->isFinalized()) {
            $request->session()->flash("form.{$form->handle()}.submitted", true);

            return [
                'redirect' => AutomagicForms::route('success', ['form' => $form->handle()]),
            ];
        }

        return [
            'redirect' => AutomagicForms::route('index', [
                'form' => $form->handle(),
                'page' => $result->nextPage,
            ]),
        ];
    }

    public function restricted(Request $request, string $form)
    {
        throw_unless(AutomagicForms::enabled(), NotFoundHttpException::class);
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        if (! $form->restricted()) {
            return redirect(AutomagicForms::route('index', ['form' => $form->handle()]));
        }

        return Inertia::render('forms-pro::AutomagicForm/Restricted', [
            'title' => $form->title(),
            'message' => $form->restrictionMessage(),
        ]);
    }

    public function submitted(Request $request, string $form)
    {
        throw_unless(AutomagicForms::enabled(), NotFoundHttpException::class);
        throw_unless($form = Facades\Form::find($form), NotFoundHttpException::class);
        throw_unless($form->get('automagic_form'), NotFoundHttpException::class);

        if (! $request->session()->get("form.{$form->handle()}.submitted")) {
            return redirect(AutomagicForms::route('index', ['form' => $form->handle()]));
        }

        return Inertia::render('forms-pro::AutomagicForm/Success', [
            'title' => $form->title(),
            'heading' => $form->get('automagic_form_success_heading', __('forms-pro::messages.automagic_form_success_heading_default')),
            'message' => $form->get('automagic_form_success_message', __('forms-pro::messages.automagic_form_success_message_default')),
            'showReset' => (bool) $form->get('automagic_form_show_reset'),
            'resetLabel' => $form->get('automagic_form_reset_label', __('forms-pro::messages.automagic_form_reset_label_default')),
            'resetUrl' => AutomagicForms::route('index', ['form' => $form->handle()]),
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

    private function previousPageLabel(array $contents): ?string
    {
        $show = $contents['show_previous_button'] ?? filled($contents['previous_page_label'] ?? null);

        if (! $show) {
            return null;
        }

        return filled($contents['previous_page_label'] ?? null)
            ? $contents['previous_page_label']
            : __('Previous Page');
    }

    private function previousPage(Form $form, string $currentPage, array $data): ?array
    {
        $path = (new PageLogic($form))->pathTo($data, $currentPage);

        // The current page comes from the URL, so the logic may not route to it at all.
        if (end($path) !== $currentPage) {
            return null;
        }

        array_pop($path);

        if (! $previousPageId = end($path)) {
            return null;
        }

        return $form->formFields()->pages()->where('id', $previousPageId)->first();
    }

    private function progress(Form $form, string $currentPage, array $data): ?int
    {
        $path = (new PageLogic($form))->path($data);
        $currentPageIndex = array_search($currentPage, $path, true);

        // The current page comes from the URL, so the logic may not route to it at all.
        if ($currentPageIndex === false || count($path) === 1) {
            return null;
        }

        return (int) round(($currentPageIndex + 1) / (count($path) + 1) * 100);
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
