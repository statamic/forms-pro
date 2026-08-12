<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Slack;
use Statamic\FormsPro\FormConnections\Slack\Rules\SlackChannel;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class SlackConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $request->validate([
            'configs' => ['present', 'array'],
            'configs.*' => ['array'],
            'configs.*.channel' => ['required', new SlackChannel],
            'configs.*.heading' => ['nullable', 'string', 'max:2000'],
        ]);

        $fields = Slack::blueprint($form)->fields();

        $configs = collect($request->configs)
            ->map(function (array $config) use ($fields): array {
                $config = Arr::removeNullValues($config);

                $values = $fields
                    ->addValues(Arr::except($config, ['_id', 'id', 'enabled', 'conditions']))
                    ->process()
                    ->values()
                    ->all();

                return Arr::removeNullValues([
                    'id' => Arr::get($config, 'id') ?? Str::random(8),
                    ...$values,
                    'enabled' => Arr::get($config, 'enabled') === false ? false : null,
                    'include_fields' => Arr::get($values, 'include_fields') === false ? false : null,
                    'link_to_submission' => Arr::get($values, 'link_to_submission') === false ? false : null,
                    'conditions' => ConnectionLogic::normalize(Arr::get($config, 'conditions') ?? []),
                ]);
            })
            ->values()
            ->all();

        $form->connections($form->connections()->put('slack', $configs))->save();
    }
}
