<?php

namespace Statamic\FormsPro\FormConnections\Twilio\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TwilioSender implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(\+[1-9]\d{1,14}|MG[0-9a-f]{32})$/i', $value)) {
            $fail('forms-pro::validation.twilio_sender')->translate();
        }
    }
}
