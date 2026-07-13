<?php

namespace Statamic\FormsPro\Http\Middleware;

use Illuminate\Http\Request;
use Statamic\Facades\Site;
use Statamic\Statamic;

class HandleInertiaRequests extends \Statamic\Http\Middleware\CP\HandleInertiaRequests
{
    public function share(Request $request): array
    {
        $parent = parent::share($request);
        $currentSite = Site::current();

        Statamic::provideToScript([
            'sites' => [
                [
                    'name' => $currentSite->name(),
                    'handle' => $currentSite->handle(),
                    'lang' => $currentSite->locale(),
                    'direction' => $currentSite->direction(),
                ]
            ],
        ]);

        return array_filter([
            ...$parent,
            '_statamic' => [
                ...$parent['_statamic'],
                'cmsName' => $currentSite->name(),
            ],
        ]);
    }
}