<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Support\Arr;

class MailchimpMarketingPermissionsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['list' => ['required', 'string']]);

        $key = "forms-pro.mailchimp.marketing-permissions.{$request->list}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $permissions = $this->permissions($request->list);

        // An audience only reveals its permissions once it has a contact, so the empty
        // state isn't cached — the next submission should be enough to resolve it.
        if ($permissions['state'] !== 'no_contacts') {
            Cache::put($key, $permissions, now()->addMinutes(5));
        }

        return $permissions;
    }

    private function permissions(string $audience): array
    {
        $mailchimp = app(MailchimpClient::class);

        if (! Arr::get($mailchimp->audience($audience), 'marketing_permissions')) {
            return ['state' => 'not_enabled', 'permissions' => []];
        }

        $permissions = $mailchimp->marketingPermissions($audience);

        return [
            'state' => $permissions ? 'ok' : 'no_contacts',
            'permissions' => $permissions,
        ];
    }
}
