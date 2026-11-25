<?php

namespace Statamic\FormsPro\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class NotWithUniqueInstances implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value && ($this->data['unique_instances'] ?? false)) {
            $fail('forms-pro::messages.automagic_form_unique_instances')->translate();
        }
    }
}
