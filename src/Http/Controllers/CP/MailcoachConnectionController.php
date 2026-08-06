<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\FormsPro\FormConnections\Mailcoach;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class MailcoachConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $formField = Rule::in($form->formFields()->fields()->keys()->all());

        $request->validate([
            'list' => ['required', 'uuid'],
            'email_field' => ['required', $formField],
            'first_name_field' => ['nullable', $formField],
            'last_name_field' => ['nullable', $formField],
            'consent_field' => ['nullable', $formField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.key' => ['required', 'string'],
            'attributes.*.value' => ['required', 'string'],
            'skip_confirmation' => ['boolean'],
        ]);

        $config = Mailcoach::blueprint($form)->fields()
            ->addValues($request->all())
            ->process()
            ->values()
            ->filter()
            ->all();

        $config['attributes'] = collect(Arr::get($config, 'attributes', []))
            ->map(fn (array $attribute) => Arr::except($attribute, 'id'))
            ->all() ?: null;

        $form->connections($form->connections()->put('mailcoach', Arr::removeNullValues($config)))->save();
    }
}
