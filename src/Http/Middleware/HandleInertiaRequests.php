<?php

namespace Statamic\FormsPro\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Statamic\Facades\Addon;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Site;
use Statamic\Statamic;

class HandleInertiaRequests extends \Statamic\Http\Middleware\CP\HandleInertiaRequests
{
    public function handle(Request $request, Closure $next)
    {
        $response = parent::handle($request, $next);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

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

    private function logoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $container = AssetContainer::find(config('statamic.forms-pro.automagic_forms.asset_container') ?? AssetContainer::all()->first()?->handle());

        return $container?->asset($path)?->url();
    }
}
