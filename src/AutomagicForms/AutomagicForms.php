<?php

namespace Statamic\FormsPro\AutomagicForms;

use Statamic\Facades\Site;

class AutomagicForms
{
    public static function route(string $name, array $params = [], ?string $site = null, bool $absolute = true): string
    {
        $site ??= Site::current()->handle();

        return route("statamic.forms-pro.form.{$site}.{$name}", $params, $absolute);
    }
}
