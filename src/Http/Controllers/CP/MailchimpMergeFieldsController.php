<?php

namespace Statamic\FormsPro\Http\Controllers\CP;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Statamic\FormsPro\FormConnections\Mailchimp;
use Statamic\FormsPro\FormConnections\Mailchimp\MailchimpClient;
use Statamic\Http\Controllers\CP\CpController;

class MailchimpMergeFieldsController extends CpController
{
    public function __invoke(Request $request, $form): array
    {
        $request->validate(['list' => ['required', 'string']]);

        return Cache::remember(
            "forms-pro.mailchimp.merge-fields.{$request->list}",
            now()->addMinutes(5),
            fn () => collect(app(MailchimpClient::class)->mergeFields($request->list))
                ->reject(fn (array $mergeField) => in_array($mergeField['tag'], Mailchimp::DEDICATED_MERGE_TAGS))
                ->values()
                ->all()
        );
    }
}
