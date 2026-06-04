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

class FormController extends Controller
{
    public function index(Request $request, $form)
    {
        throw_unless($form = Form::find($form), NotFoundHttpException::class);

        $blueprint = $form->blueprint();
        $fields = $blueprint->fields()->preProcess();

        return Inertia::render('forms-pro::Form', [
            'title' => $form->title(),
            'pages' => $form->blueprint()->tabs()->map(fn (Tab $tab) => [
                'id' => $tab->handle(),
                'display' => $tab->contents()['display'],
                'button_label' => $tab->contents()['button_label'] ?? null,
                'previous_page_label' => $tab->contents()['previous_page_label'] ?? null,
            ])->values()->all(),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
        ]);
    }
}
