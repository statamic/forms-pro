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
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        $request->validate([
            'list' => ['required', 'string'],
            'email_field' => ['required', $isFormField],
            'first_name_field' => ['nullable', $isFormField],
            'last_name_field' => ['nullable', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'merge_fields' => ['nullable', 'array'],
            'merge_fields.*.key' => ['required', 'string', 'distinct', 'regex:/^[A-Z0-9_]+$/', Rule::notIn(Mailchimp::DEDICATED_MERGE_TAGS)],
            'merge_fields.*.value' => ['required', $isFormField],
            'marketing_permissions' => ['nullable', 'array'],
            'marketing_permissions.*.permission' => ['required', 'string', 'distinct'],
            'marketing_permissions.*.field' => ['required', $isFormField],
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
