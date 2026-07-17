<?php

namespace Statamic\FormsPro\Http\Middleware;

use Illuminate\Http\Request;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
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
                $site = [
                    'name' => $currentSite->name(),
                    'handle' => $currentSite->handle(),
                    'url' => $currentSite->absoluteUrl(),
                    'lang' => $currentSite->locale(),
                    'direction' => $currentSite->direction(),
                ],
            ],
        ]);

        return array_filter([
            ...$parent,
            'site' => $site,
            'settings' => $this->settings(),
            '_statamic' => [
                ...$parent['_statamic'],
                'cmsName' => $currentSite->name(),
            ],
        ]);
    }

    private function settings(): array
    {
        $settings = Addon::get('statamic/forms-pro')->settings();

        return [
            'logoUrl' => $this->logoUrl($settings->get('logo')),
            'brandColor' => $settings->get('brand_color'),
            'background' => $settings->get('background'),
        ];
    }

    private function logoUrl(string $path): ?string
    {
        $container = AssetContainer::all()->first();

        return $container?->asset($path)?->url();
    }
}
