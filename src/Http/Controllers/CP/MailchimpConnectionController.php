<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\FormsPro\FormConnections\Mailchimp;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class MailchimpConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $formField = Rule::in($form->formFields()->fields()->keys()->all());

        $request->validate([
            'list' => ['required', 'string'],
            'email_field' => ['required', $formField],
            'first_name_field' => ['nullable', $formField],
            'last_name_field' => ['nullable', $formField],
            'consent_field' => ['nullable', $formField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*.key' => ['required', 'string', 'regex:/^[A-Z0-9_]+$/', Rule::notIn(Mailchimp::DEDICATED_MERGE_TAGS)],
            'merge_fields.*.value' => ['required', 'string'],
            'skip_confirmation' => ['boolean'],
        ]);

        $config = Mailchimp::blueprint($form)->fields()
            ->addValues($request->all())
            ->process()
            ->values()
            ->filter()
            ->all();

        $config['merge_fields'] = collect(Arr::get($config, 'merge_fields', []))
            ->map(fn (array $mergeField) => Arr::except($mergeField, 'id'))
            ->all() ?: null;

        $form->connections($form->connections()->put('mailchimp', Arr::removeNullValues($config)))->save();
    }
}
