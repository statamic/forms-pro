<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\FormsPro\FormConnections\Kit;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class KitConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        $request->validate([
            'form' => ['nullable', 'integer'],
            'email_field' => ['required', $isFormField],
            'first_name_field' => ['nullable', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'distinct'],
            'custom_fields' => ['nullable', 'array'],
            'custom_fields.*.key' => ['required', 'string', 'distinct'],
            'custom_fields.*.value' => ['required', $isFormField],
        ]);

        $config = Kit::blueprint($form)->fields()
            ->addValues($request->all())
            ->process()
            ->values()
            ->all();

        $config['custom_fields'] = collect(Arr::get($config, 'custom_fields'))
            ->map(fn (array $customField) => Arr::except($customField, 'id'))
            ->all() ?: null;

        $form->connections($form->connections()->put('kit', Arr::removeNullValues($config)))->save();
    }
}
