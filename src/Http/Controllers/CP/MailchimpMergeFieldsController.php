<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnections\Mailchimp;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpException;
use Statamic\Http\Controllers\CP\CpController;

class MailchimpMergeFieldsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['list' => ['required', 'alpha_num:ascii']]);

        $mailchimp = app(MailchimpClient::class);

        try {
            $mergeFields = Cache::remember(
                "forms-pro.mailchimp.merge-fields.{$mailchimp->fingerprint()}.{$request->list}",
                now()->addMinutes(5),
                fn () => collect($mailchimp->mergeFields($request->list))
                    ->reject(fn (array $mergeField) => in_array($mergeField['tag'], Mailchimp::DEDICATED_MERGE_TAGS))
                    ->values()
                    ->all()
            );

            return ['merge_fields' => $mergeFields, 'error' => null];
        } catch (ConnectionException|MailchimpException $e) {
            return ['merge_fields' => [], 'error' => $e->getMessage()];
        }
    }
}
