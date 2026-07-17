<?php

namespace Statamic\FormsPro\FormFieldtypes;

use Statamic\Forms\Fields\FormFieldtype;
use Statamic\FormsPro\Fieldtypes\Address as AddressFieldtype;
use Statamic\Support\Arr;

class Address extends FormFieldtype
{
    protected static $fieldtype = 'address';

    protected $description = 'Collects a postal address.';

    protected $icon = 'location-pin';

    protected $categories = ['contact'];

    protected function configFieldItems(): array
    {
        return (new AddressFieldtype)->configFieldItems();
    }

    public function toFieldArray(): array
    {
        return [
            'type' => 'address',
            ...Arr::except($this->config(), ['type']),
        ];
    }

    public function example(): ?array
    {
        return [
            'config' => [
                'display' => 'Address',
            ],
            'value' => [
                'line_1' => '4 Privet Drive',
                'city' => 'Little Whinging',
                'region' => 'Surrey',
                'postcode' => 'LW1 4PD',
                'country' => 'GB',
            ],
        ];
    }

    public function view(): string
    {
        $language = config('statamic.templates.language', 'antlers');

        $views = [
            'forms-pro::forms.fields.address',
            "forms-pro::forms.{$language}.fields.address",
        ];

        return collect($views)->first(fn (string $view): bool => view()->exists($view))
            ?? parent::view();
    }
}
