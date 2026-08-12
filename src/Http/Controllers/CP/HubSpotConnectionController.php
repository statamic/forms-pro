<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\FormsPro\FormConnections\HubSpot;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class HubSpotConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $isFormField = Rule::in($form->formFields()->fields()->keys()->all());

        $request->validate([
            'form' => ['required', 'uuid'],
            'email_field' => ['required', $isFormField],
            'consent_field' => ['nullable', $isFormField],
            'consent_to_process_field' => ['nullable', $isFormField],
            'legitimate_interest_subscription' => ['nullable', 'integer'],
            'properties' => ['nullable', 'array'],
            'properties.*.key' => ['required', 'string', 'distinct', Rule::notIn(['email'])],
            'properties.*.value' => ['required', $isFormField],
            'communications' => ['nullable', 'array'],
            'communications.*.subscription' => ['required', 'integer', 'distinct'],
            'communications.*.field' => ['required', $isFormField],
        ]);

        $config = HubSpot::blueprint($form)->fields()
            ->addValues($request->all())
            ->process()
            ->values()
            ->all();

        $form->connections($form->connections()->put('hubspot', Arr::removeNullValues($config)))->save();
    }
}
