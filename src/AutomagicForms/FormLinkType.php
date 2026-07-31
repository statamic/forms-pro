<?php

namespace Statamic\FormsPro\AutomagicForms;

use Statamic\Contracts\Data\Localization;
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

        $site = $parent instanceof Localization ? $parent->locale() : null;

        return AutomagicForms::route('index', ['form' => $form->handle()], site: $site, absolute: false);
    }

    public function fieldtype(Field $field): ?array
    {
        return ['type' => 'form', 'max_items' => 1];
    }
}
