<?php

namespace Statamic\FormsPro\AutomagicForms;

use Statamic\Contracts\Forms\Form;
use Statamic\Facades\Site;

class AutomagicForms
{
    public static function enabled(): bool
    {
        return config('statamic.forms-pro.automagic_forms.enabled');
    }

    public static function availableFor(Form $form): bool
    {
        return $form->get('automagic_form') && ! $form->hasUniqueInstances();
    }

    public static function route(string $name, array $params = [], ?string $site = null, bool $absolute = true): string
    {
        $site ??= Site::current()->handle();

        return route("statamic.forms-pro.form.{$site}.{$name}", $params, $absolute);
    }
}
