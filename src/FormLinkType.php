<?php

namespace Statamic\FormsPro;

use Statamic\Facades\Form;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Link\LinkType;

class FormLinkType extends LinkType
{
    protected ?string $icon = 'forms';

    public function title(): string
    {
        return 'Form';
    }

    public function resolve(string $id, $parent = null, bool $localize = false): mixed
    {
        if (! $form = Form::find($id)) {
            return null;
        }

        return route('statamic.forms-pro.form.store', ['form' => $form->handle()], absolute: false);
    }

    public function fieldtype(Field $field): ?array
    {
        return ['type' => 'form', 'max_items' => 1];
    }
}
