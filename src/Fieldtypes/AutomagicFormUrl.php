<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Fields\Fieldtype;

class AutomagicFormUrl extends Fieldtype
{
    protected $selectable = false;

    public function preload()
    {
        if (! request()->route('form')) {
            return null;
        }

        return [
            'url' => route('statamic.forms-pro.form.index', request()->route('form')->handle()),
        ];
    }
}