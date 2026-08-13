<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\Http\Controllers\CP\CpController;

class MailchimpGroupsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['list' => ['required', 'alpha_num:ascii']]);

        $mailchimp = app(MailchimpClient::class);

        return Cache::remember(
            "forms-pro.mailchimp.groups.{$mailchimp->fingerprint()}.{$request->list}",
            now()->addMinutes(5),
            fn () => $mailchimp->groups($request->list)
        );
    }
}
