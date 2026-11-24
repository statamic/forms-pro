<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Contracts\Forms\Form;
use Statamic\Facades\Form as Forms;
use Statamic\Fields\Fieldtype;
use Statamic\Forms\Fields\FormField;
use Statamic\Support\Arr;

class MailchimpGroupOption extends Fieldtype
{
    protected $selectable = false;

    protected $defaultable = false;

    public function preload(): array
    {
        return [
            'options' => $this->fieldsWithOptions(Forms::find($this->config('form'))),
        ];
    }

    /**
     * The options of every form field that has any, keyed by handle. A row picks out the
     * ones belonging to the field it's mapped to, and validation uses it to decide
     * whether that row needs an option at all.
     */
    public function fieldsWithOptions(Form $form): array
    {
        return $form->formFields()->fields()
            ->map(function (FormField $formField): array {
                $field = $formField->toField();

                if (! Arr::has($field->config(), 'options')) {
                    return [];
                }

                return collect(Arr::get($field->fieldtype()->preload(), 'options'))
                    ->filter(fn ($option): bool => is_array($option))
                    ->map(fn (array $option): array => [
                        'value' => $value = $option['value'] ?? $option['key'] ?? null,
                        'label' => $option['label'] ?? $value,
                    ])
                    ->reject(fn (array $option): bool => $option['value'] === null)
                    ->values()
                    ->all();
            })
            ->filter()
            ->all();
    }
}
