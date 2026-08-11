<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;

class HubSpotProperties extends Fieldtype
{
    protected static $handle = 'hubspot_properties';

    protected $selectable = false;

    protected $defaultable = false;

    public function preload(): array
    {
        $field = new Field('field', [
            'type' => 'form_fields',
            'form' => $this->config('form'),
            'placeholder' => 'forms-pro::messages.hubspot_connection_property_placeholder',
            'clearable' => true,
        ]);

        return [
            'field' => [
                'config' => $field->toPublishArray(),
                'meta' => $field->meta(),
            ],
        ];
    }
}
