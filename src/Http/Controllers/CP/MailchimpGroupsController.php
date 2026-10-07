<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnectors\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnectors\Mailchimp\MailchimpException;
use Statamic\Http\Controllers\CP\CpController;

class MailchimpGroupsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['list' => ['required', 'alpha_num:ascii']]);

        $mailchimp = app(MailchimpClient::class);

        try {
            $groups = Cache::remember(
                "forms-pro.mailchimp.groups.{$mailchimp->fingerprint()}.{$request->list}",
                now()->addMinutes(5),
                fn () => $mailchimp->groups($request->list)
            );

            return ['groups' => $groups, 'error' => null];
        } catch (ConnectionException|MailchimpException $e) {
            return ['groups' => [], 'error' => $e->getMessage()];
        }
    }
}
