<?php

namespace Statamic\FormsPro\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Form;
use Statamic\Fields\Tab;
use Statamic\Http\Controllers\Controller;
use Statamic\Support\Arr;
use Statamic\Support\Str;

use function Statamic\trans as __;

class FormController extends Controller
{
    public function index(Request $request, $form)
    {
        throw_unless($form = Form::find($form), NotFoundHttpException::class);

        $blueprint = $form->blueprint();
        $fields = $blueprint->fields()->preProcess();
        $formPages = $form->formFields()->pages()->keyBy(fn (array $page) => $page['id'] ?? 'main');

        return Inertia::render('forms-pro::Form', [
            'title' => $form->title(),
            'pages' => $form->blueprint()->tabs()->map(fn (Tab $tab) => [
                'id' => $tab->handle(),
                'display' => $this->customTabDisplay($tab, $formPages->get($tab->handle(), [])),
                'button_label' => $tab->contents()['button_label'] ?? null,
                'previous_page_label' => $tab->contents()['previous_page_label'] ?? null,
            ])->values()->all(),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
        ]);
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
}
