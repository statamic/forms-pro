<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\FormsPro\Fieldtypes\MailchimpGroupOption;
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
            'groups' => ['nullable', 'array'],
            'groups.*.group' => ['required', 'string', 'distinct'],
            'groups.*.field' => ['required', $isFormField],
            'groups.*.option' => ['nullable', 'string'],
            'marketing_permissions' => ['nullable', 'array'],
            'marketing_permissions.*.permission' => ['required', 'string', 'distinct'],
            'marketing_permissions.*.field' => ['required', $isFormField],
            'skip_confirmation' => ['boolean'],
            ...$this->groupOptionRules($request, $form),
        ]);

        $config = Mailchimp::blueprint($form)->fields()
            ->addValues($request->all())
            ->process()
            ->values()
            ->filter()
            ->all();

        foreach (['merge_fields', 'groups'] as $grid) {
            $config[$grid] = collect(Arr::get($config, $grid))
                ->map(fn (array $row) => Arr::except($row, 'id'))
                ->all() ?: null;
        }

        $form->connections($form->connections()->put('mailchimp', Arr::removeNullValues($config)))->save();
    }

    /**
     * A group mapped to a field with options needs to say which one, otherwise it would
     * be applied whenever any option is chosen. Fields without options don't.
     */
    private function groupOptionRules(Request $request, $form): array
    {
        $fieldsWithOptions = array_keys(new MailchimpGroupOption()->fieldsWithOptions($form));

        return collect($request->input('groups'))
            ->filter(fn (array $group): bool => in_array(Arr::get($group, 'field'), $fieldsWithOptions))
            ->keys()
            ->mapWithKeys(fn (int $index): array => ["groups.{$index}.option" => ['required', 'string']])
            ->all();
    }
}
