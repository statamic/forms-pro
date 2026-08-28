<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Statamic\FormsPro\AutomagicForms\AutomagicForms;

class AutomagicFormUrl extends Fieldtype
{
    protected $selectable = false;

    public function preload()
    {
        if (! request()->route('form')) {
            return null;
        }

        return [
            'url' => AutomagicForms::route('index', ['form' => request()->route('form')->handle()]),
        ];
    }
}
