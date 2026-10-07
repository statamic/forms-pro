<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Statamic\FormsPro\FormConnectors\GoogleSheets;
use Statamic\FormsPro\FormConnectors\GoogleSheets\GoogleSheetsException;
use Statamic\FormsPro\FormConnectors\GoogleSheets\Rules\SpreadsheetUrl;
use Statamic\Http\Controllers\CP\CpController;

class GoogleSheetsTabsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['spreadsheet' => ['required', new SpreadsheetUrl]]);

        try {
            GoogleSheets::forgetTabs($request->spreadsheet);

            return ['tabs' => GoogleSheets::tabs($request->spreadsheet), 'error' => null];
        } catch (ConnectionException|GoogleSheetsException $e) {
            return ['tabs' => [], 'error' => $e->getMessage()];
        }
    }
}
