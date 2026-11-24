<?php

namespace Statamic\FormsPro\FormConnectors\GoogleSheets\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\FormsPro\FormConnectors\GoogleSheets;

class SpreadsheetUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! GoogleSheets::extractSpreadsheetIdFromUrl($value)) {
            $fail('forms-pro::validation.google_sheets_spreadsheet_url')->translate();
        }
    }
}
