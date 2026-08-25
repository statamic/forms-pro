<?php

namespace Statamic\FormsPro\FormConnections\GoogleSheets\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;
use Statamic\FormsPro\FormConnections\GoogleSheets;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsException;
use Statamic\Support\Arr;

class SpreadsheetTab implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $spreadsheet = Arr::get($this->data, 'spreadsheet');

        if (! is_string($spreadsheet) || ! GoogleSheets::extractSpreadsheetIdFromUrl($spreadsheet)) {
            return;
        }

        try {
            $tabs = GoogleSheets::tabs($spreadsheet);
        } catch (GoogleSheetsException $e) {
            throw ValidationException::withMessages(['spreadsheet' => $e->getMessage()]);
        }

        if (! array_key_exists((int) $value, $tabs)) {
            $fail('validation.in')->translate();
        }
    }
}
