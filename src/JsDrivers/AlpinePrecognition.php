<?php

namespace Statamic\FormsPro\JsDrivers;

use Statamic\Forms\JsDrivers\AlpinePrecognition as BaseAlpinePrecognition;
use Statamic\FormsPro\JsDrivers\Concerns\InteractsWithPages;
use Statamic\Statamic;

class AlpinePrecognition extends BaseAlpinePrecognition
{
    use InteractsWithPages;

    public static function handle(): string
    {
        return 'forms_pro_alpine_precognition';
    }

    /**
     * Keep core's Precognition `$form('post', ...)` wrapper intact and merge the
     * page state alongside it inside the `formsPro` component.
     */
    protected function renderAlpineXData($xData, $alpineScope)
    {
        $action = $this->form->actionUrl();
        $fields = Statamic::modify($xData)->toJson()->entities();

        $form = "\$form('post', '{$action}', {$fields})";

        if (($errors = $this->getInitialFormErrors())?->count()) {
            $errors = Statamic::modify($errors)->toJson()->entities();
            $form .= ".setErrors({$errors})";
        }

        // The `$form(...)` value is a JS expression, not data, so it can't be
        // JSON-encoded directly. Encode the object with a placeholder, then swap
        // the placeholder for the live expression.
        $placeholder = '__FORMS_PRO_PRECOGNITION_FORM__';

        $object = Statamic::modify([$alpineScope => $placeholder])->toJson()->entities();

        // `entities()` has encoded the placeholder's surrounding quotes to `&quot;`.
        $object = str_replace('&quot;'.$placeholder.'&quot;', $form, $object);

        return $this->wrapInFormsProComponent($object);
    }
}
