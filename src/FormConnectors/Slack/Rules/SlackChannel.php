<?php

namespace Statamic\FormsPro\FormConnectors\Slack\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SlackChannel implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[CGD][A-Z0-9]{8,}$/', $value)) {
            $fail('forms-pro::validation.slack_channel')->translate();
        }
    }
}
