<?php

namespace Statamic\FormsPro\JsDrivers;

use Statamic\Forms\JsDrivers\Alpine as BaseAlpine;
use Statamic\FormsPro\JsDrivers\Concerns\InteractsWithPages;
use Statamic\Statamic;

class Alpine extends BaseAlpine
{
    use InteractsWithPages;

    public static function handle(): string
    {
        return 'forms_pro_alpine';
    }

    /**
     * Wrap the field values and page config in the `formsPro` component
     * so the form's x-data carries both the field bindings and page state.
     */
    protected function renderAlpineXData($xData, $alpineScope)
    {
        if (is_string($alpineScope)) {
            $xData = [$alpineScope => $xData];
        }

        $object = Statamic::modify($xData)->toJson()->entities();

        return $this->wrapInFormsProComponent($object);
    }
}
