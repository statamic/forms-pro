<?php

namespace Statamic\FormsPro\Tags;

use Statamic\Support\Arr;
use Statamic\Tags\Tags;

class Turnstile extends Tags
{
    public function index(): string
    {
        $siteKey = config('forms-pro.turnstile.site_key');
        $secretKey = config('forms-pro.turnstile.secret_key');

        if (! $siteKey || ! $secretKey) {
            return '';
        }

        if ($this->onIntermediatePage()) {
            return '';
        }

        return sprintf('<div data-forms-pro-turnstile data-sitekey="%s"></div>', e($siteKey));
    }

    /**
     * Multi-page forms only verify Turnstile when they're finalized, which can
     * only happen on the last page, so earlier pages skip the widget. JS-driven
     * forms render every page up-front, so they always keep it.
     */
    private function onIntermediatePage(): bool
    {
        if ($this->context->value('js_driver')) {
            return false;
        }

        $pages = $this->context->value('pages');

        if (! is_array($pages) || count($pages) <= 1) {
            return false;
        }

        return Arr::get($this->context->value('page'), 'id') !== Arr::get(Arr::last($pages), 'id');
    }
}
