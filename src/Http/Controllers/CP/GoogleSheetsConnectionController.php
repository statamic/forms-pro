<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\FormsPro\FormConnections\GoogleSheets;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsException;
use Statamic\FormsPro\FormConnections\GoogleSheets\Rules\SpreadsheetUrl;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

use function Statamic\trans as __;

class GoogleSheetsConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $reserved = $form->formFields()->fields()->keys()->intersect(array_keys(GoogleSheets::metaColumns()));

        throw_if($reserved->isNotEmpty(), ValidationException::withMessages([
            'fields' => __('forms-pro::validation.google_sheets_reserved_column', ['field' => $reserved->first()]),
        ]));

        $request->validate([
            'spreadsheet' => ['required', new SpreadsheetUrl],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'distinct', Rule::in(array_keys(GoogleSheets::columns($form)))],
        ]);

        $request->validate(['tab' => ['required', 'integer', Rule::in(array_keys($this->tabs($request->spreadsheet)))]]);

        $config = GoogleSheets::blueprint($form)->fields()
            ->addValues([
                ...Arr::removeNullValues($request->all()),
                'tab' => (int) $request->tab,
            ])
            ->process()
            ->values()
            ->all();

        $form->connections($form->connections()->put('google_sheets', Arr::removeNullValues($config)))->save();
    }

    private function tabs(string $url): array
    {
        try {
            return GoogleSheets::tabs($url);
        } catch (GoogleSheetsException $e) {
            throw ValidationException::withMessages(['spreadsheet' => $e->getMessage()]);
        }
    }
}
