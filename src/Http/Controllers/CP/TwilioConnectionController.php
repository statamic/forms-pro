<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Statamic\Forms\Connections\ConnectionLogic;
use Statamic\FormsPro\FormConnections\Twilio;
use Statamic\FormsPro\FormConnections\Twilio\Rules\TwilioRecipient;
use Statamic\FormsPro\FormConnections\Twilio\Rules\TwilioSender;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class TwilioConnectionController extends CpController
{
    public function update(Request $request, $form): void
    {
        $request->validate([
            'configs' => ['present', 'array'],
            'configs.*' => ['array'],
            'configs.*.to' => ['required', new TwilioRecipient($form)],
            'configs.*.from' => ['required', new TwilioSender],
            'configs.*.body' => ['required', 'string', 'max:1600'],
            'configs.*.country_code' => ['nullable', 'string', 'regex:/^\+[1-9]\d{0,3}$/'],
        ]);

        $configs = collect($request->configs)
            ->map(function (array $config) use ($form): array {
                $values = Twilio::blueprint($form)->fields()
                    ->addValues(Arr::except($config, ['_id', 'id', 'enabled', 'conditions']))
                    ->process()
                    ->values()
                    ->all();

                return Arr::removeNullValues([
                    'id' => Arr::get($config, 'id') ?? Str::random(8),
                    ...$values,
                    'enabled' => Arr::get($config, 'enabled') === false ? false : null,
                    'conditions' => ConnectionLogic::normalize(Arr::get($config, 'conditions') ?? []),
                ]);
            })
            ->values()
            ->all();

        $form->connections($form->connections()->put('twilio', $configs))->save();
    }
}
