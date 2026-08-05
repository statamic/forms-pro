<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Slack\Rules\SlackChannel;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class SlackConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $request->validate([
            'configs' => ['present', 'array'],
            'configs.*' => ['array'],
            'configs.*.channel' => ['required', new SlackChannel],
            'configs.*.channel_name' => ['nullable', 'string'],
            'configs.*.message' => ['nullable', 'string', 'max:2000'],
            'configs.*.include_fields' => ['sometimes', 'boolean'],
            'configs.*.fields' => ['nullable', 'array'],
            'configs.*.fields.*' => ['string', Rule::in($form->formFields()->fields()->keys())],
            'configs.*.link_to_submission' => ['sometimes', 'boolean'],
        ]);

        $configs = collect($request->configs)
            ->map(fn (array $config) => Arr::removeNullValues(array_merge(Arr::except($config, '_id'), [
                'enabled' => Arr::get($config, 'enabled') === false ? false : null,
                'include_fields' => Arr::get($config, 'include_fields') === false ? false : null,
                'link_to_submission' => Arr::get($config, 'link_to_submission') === false ? false : null,
                'conditions' => ConnectionLogic::normalize(Arr::get($config, 'conditions') ?? []),
            ])))
            ->values()
            ->all();

        $form->connections($form->connections()->put('slack', $configs))->save();
    }
}
