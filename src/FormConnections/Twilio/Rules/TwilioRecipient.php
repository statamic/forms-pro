<?php

namespace Statamic\FormsPro\FormConnections\Twilio\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Contracts\Forms\Form;
use Statamic\Support\Str;

class TwilioRecipient implements ValidationRule
{
    public function __construct(private Form $form) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isFormField($value) && ! preg_match('/^\+[1-9]\d{1,14}$/', $value)) {
            $fail('forms-pro::validation.twilio_recipient')->translate();
        }
    }

    private function isFormField(string $value): bool
    {
        return Str::startsWith($value, 'field:')
            && $this->form->formFields()->fields()->has(Str::after($value, 'field:'));
    }
}
