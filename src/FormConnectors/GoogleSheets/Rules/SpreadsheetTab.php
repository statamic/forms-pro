<?php

namespace Statamic\FormsPro\FormConnectors\GoogleSheets\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Validation\ValidationException;
use Statamic\FormsPro\FormConnectors\GoogleSheets;
use Statamic\FormsPro\FormConnectors\GoogleSheets\GoogleSheetsException;
use Statamic\Support\Arr;
use Statamic\Support\Str;

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
        $prefix = Str::contains($attribute, '.') ? Str::beforeLast($attribute, '.').'.' : '';

        $spreadsheet = Arr::get($this->data, $prefix.'spreadsheet');

        if (! is_string($spreadsheet) || ! GoogleSheets::extractSpreadsheetIdFromUrl($spreadsheet)) {
            return;
        }

        try {
            $tabs = GoogleSheets::tabs($spreadsheet);
        } catch (ConnectionException|GoogleSheetsException $e) {
            throw ValidationException::withMessages([$prefix.'spreadsheet' => $e->getMessage()]);
        }

        if (! array_key_exists((int) $value, $tabs)) {
            $fail('validation.in')->translate();
        }
    }
}
