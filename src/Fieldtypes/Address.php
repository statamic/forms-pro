<?php

namespace Statamic\FormsPro\Fieldtypes;

use Illuminate\Support\Collection;
use Statamic\Dictionaries\Countries;
use Statamic\Dictionaries\Item;
use Statamic\Fields\Fields;
use Statamic\Fields\Fieldtype;
use Statamic\Fields\Validator;
use Statamic\Fields\Values;
use Statamic\Support\Arr;
use Statamic\Support\Str;

use function Statamic\trans as __;

class Address extends Fieldtype
{
    protected $selectable = false;

    protected $defaultable = false;

    private const ADDRESS_FIELDS = ['line_1', 'line_2', 'city', 'region', 'postcode', 'country'];

    private const HTML_AUTOCOMPLETE_ATTRIBUTES = [
        'line_1' => 'address-line1',
        'line_2' => 'address-line2',
        'city' => 'address-level2',
        'region' => 'address-level1',
        'postcode' => 'postal-code',
        'country' => 'country',
    ];

    public function configFieldItems(): array
    {
        return [
            'default_country' => [
                'display' => __('forms-pro::fieldtypes.address.config.default_country'),
                'type' => 'select',
                'searchable' => true,
                'clearable' => true,
                'options' => static::countryOptions(),
                'width' => 50,
            ],
        ];
    }

    private static function countryOptions(): array
    {
        return collect((new Countries)->optionItems())
            ->mapWithKeys(fn (Item $item): array => [$item['iso2'] => $item['name']])
            ->all();
    }

    public function fields(): Fields
    {
        return new Fields($this->fieldItems(), $this->field()?->parent(), $this->field());
    }

    private function fieldItems(): array
    {
        $required = $this->isRequired();

        return collect([
            'line_1' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.line_1'),
                'validate' => $required ? ['required'] : [],
            ],
            'line_2' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.line_2'),
                'validate' => [],
            ],
            'city' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.city'),
                'validate' => $required ? ['required'] : [],
            ],
            'region' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.region'),
                'validate' => [],
            ],
            'postcode' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.postal_code'),
                'validate' => [],
            ],
            'country' => [
                'type' => 'text',
                'display' => __('forms-pro::fieldtypes.address.labels.country'),
                'default' => $this->config('default_country'),
                'validate' => $required ? ['required'] : [],
            ],
        ])
            ->map(fn ($field, $handle) => compact('handle', 'field'))
            ->values()
            ->all();
    }

    public function preProcess($data)
    {
        $empty = collect(self::ADDRESS_FIELDS)
            ->mapWithKeys(function (string $handle): array {
                if ($handle === 'country') {
                    return [$handle => $this->config('default_country')];
                }

                return [$handle => null];
            })
            ->all();

        return array_merge($empty, $data ?? []);
    }

    public function process($data)
    {
        if (is_array($data) && $data = Arr::removeNullValues($data)) {
            return $data;
        }
    }

    public function rules(): array
    {
        return ['array'];
    }

    public function extraRules(): array
    {
        $rules = $this
            ->fields()
            ->addValues((array) $this->field->value())
            ->validator()
            ->withContext([
                'prefix' => $this->field->validationContext('prefix'),
            ])
            ->rules();

        return collect($rules)->mapWithKeys(fn ($rules, $handle) => [$this->field->handle().'.'.$handle => $rules])->all();
    }

    public function extraValidationAttributes(): array
    {
        return collect($this->fields()->validator()->attributes())->mapWithKeys(function ($attribute, $handle) {
            return [$this->field->handle().'.'.$handle => $attribute];
        })->all();
    }

    public function augment($value)
    {
        if (! $value) {
            return null;
        }

        return new Values(array_merge($value, [
            'country_name' => $this->countryName($value),
            'formatted' => $this->formatAddress($value),
        ]));
    }

    public function preProcessIndex($data)
    {
        return $data ? $this->formatAddress($data) : null;
    }

    public function preload(): array
    {
        return [
            'labels' => $this->labels(),
            'countries' => static::countryOptions(),
            'defaultCountry' => $this->config('default_country'),
            'autocomplete' => $this->autocompleteConfig(),
        ];
    }

    public function extraRenderableFieldData(): array
    {
        return [
            'countries' => collect(static::countryOptions())
                ->map(fn (string $name, string $code): array => compact('code', 'name'))
                ->values()
                ->all(),
            'address_labels' => $this->labels()->toJson(),
            'autocomplete' => ($config = $this->autocompleteConfig()) ? json_encode($config) : null,
        ];
    }

    public function preProcessTagRenderable($data, $recursiveCallback)
    {
        $field = $this->field();

        $data['address_fields'] = collect($this->fields()->all())
            ->map(fn ($child) => $child->setForm($field->form())->setHandle($field->handle().'.'.$child->handle()))
            ->map(fn ($child): array => array_merge($recursiveCallback($child), [
                'address_handle' => $addressHandle = Str::afterLast($child->handle(), '.'),
                'html_autocomplete' => self::HTML_AUTOCOMPLETE_ATTRIBUTES[$addressHandle],
            ]))
            ->values()
            ->all();

        return $data;
    }

    public function hasJsDriverDataBinding(): bool
    {
        return false;
    }

    private function countryName(array $value): ?string
    {
        return Arr::get(static::countryOptions(), Arr::get($value, 'country'));
    }

    private function formatAddress(array $value): string
    {
        return collect(self::ADDRESS_FIELDS)
            ->map(function (string $handle) use ($value): ?string {
                if ($handle === 'country') {
                    return $this->countryName($value);
                }

                return Arr::get($value, $handle);
            })
            ->filter()
            ->implode(', ');
    }

    private function isRequired(): bool
    {
        if (! $this->field) {
            return false;
        }

        return $this->field->get('required')
            || collect(Validator::explodeRules($this->field->get('validate')))->contains('required');
    }

    private function labels(): Collection
    {
        return collect([
            'line_1', 'line_2', 'city', 'region', 'country',
            'postal_code', 'postcode', 'zip', 'eircode', 'pin',
            'state', 'province', 'county', 'prefecture', 'department',
            'emirate', 'district', 'oblast',
        ])->mapWithKeys(fn ($key) => [$key => __("forms-pro::fieldtypes.address.labels.{$key}")]);
    }

    private function autocompleteConfig(): ?array
    {
        $provider = config('forms-pro.address.autocomplete.provider');
        $key = config('forms-pro.address.autocomplete.key');

        if (! $provider || ! $key) {
            return null;
        }

        return compact('provider', 'key');
    }
}
