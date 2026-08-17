<?php

namespace Statamic\FormsPro\Tags;

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

        return sprintf('<div data-forms-pro-turnstile data-sitekey="%s"></div>', e($siteKey));
    }
}
