<?php

namespace Statamic\FormsPro\Fieldtypes;

use Statamic\Fields\Field;
use Statamic\Fields\Fieldtype;

class HubSpotCommunications extends Fieldtype
{
    protected static $handle = 'hubspot_communications';

    protected $selectable = false;

    protected $defaultable = false;

    public function preload(): array
    {
        $field = new Field('field', [
            'type' => 'form_fields',
            'form' => $this->config('form'),
            'categories' => ['choice', 'other'],
            'placeholder' => 'forms-pro::messages.hubspot_communications_placeholder',
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
