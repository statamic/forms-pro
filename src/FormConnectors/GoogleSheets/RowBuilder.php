<?php

namespace Statamic\FormsPro\FormConnectors\GoogleSheets;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Forms\Submission;
use Statamic\Fields\Value;
use Statamic\Fields\Values;
use Statamic\Support\Arr;

use function Statamic\trans as __;

class RowBuilder
{
    public function __construct(private Submission $submission, private array $config) {}

    /**
     * Keyed by field handle, which is what the column headers are named after.
     * The order is only used the first time each column is added.
     */
    public function build(): array
    {
        $selected = Arr::get($this->config, 'fields');

        return $this->fields()
            ->merge($this->meta())
            ->when($selected, fn (Collection $columns) => $columns->only($selected))
            ->all();
    }

    private function fields(): Collection
    {
        $excluded = $this->submission->form()->formFields()->fields()
            ->reject(fn ($field) => $field->fieldtype()->collectsValue())
            ->keys();

        return collect(Arr::except($this->submission->toAugmentedArray(), ['id', 'date', 'form']))
            ->filter(fn ($value) => $value instanceof Value)
            ->reject(fn (Value $value, string $handle) => $excluded->contains($handle))
            ->map(fn (Value $value) => $this->renderValue($value));
    }

    private function meta(): array
    {
        return [
            'submitted_at' => $this->submission->date()->toIso8601String(),
            'submission_id' => (string) $this->submission->id(),
        ];
    }

    private function renderValue(Value $value): string|bool
    {
        $field = $value->field();
        $type = $field->type();
        $value = $value->value();

        return match (true) {
            $type === 'assets', $type === 'form_upload' && $field->get('store') => $this->renderAssets($value),
            in_array($type, ['files', 'form_upload']) => $this->renderFilenames($value),
            in_array($type, ['select', 'radio', 'checkboxes', 'dictionary']) => $this->renderOptions($value),
            $value instanceof Values => $this->renderGroup($value),
            is_bool($value) => $value,
            is_iterable($value) => $this->renderJson($value),
            default => (string) $value,
        };
    }

    private function renderGroup(Values $values): string
    {
        return collect($values->all())
            ->filter(fn ($value) => $value instanceof Value)
            ->map(fn (Value $value) => [$value->field()->display(), $this->renderText($value)])
            ->reject(fn (array $child) => blank($child[1]))
            ->map(fn (array $child) => __($child[0]).': '.$child[1])
            ->implode("\n");
    }

    private function renderText(Value $value): string
    {
        $rendered = $this->renderValue($value);

        return is_bool($rendered) ? ($rendered ? __('Yes') : __('No')) : $rendered;
    }

    private function renderAssets(mixed $value): string
    {
        $assets = $value instanceof Asset ? collect([$value]) : collect($value?->get());

        return $assets->map(fn (Asset $asset) => $asset->absoluteUrl() ?: $asset->basename())->implode(', ');
    }

    private function renderFilenames(mixed $value): string
    {
        return collect(Arr::wrap($value))
            ->map(fn ($file) => basename((string) $file))
            ->implode(', ');
    }

    private function renderOptions(mixed $value): string
    {
        return collect(is_iterable($value) ? $value : [$value])
            ->map(fn ($option) => $this->optionLabel($option))
            ->reject(fn (string $label) => $label === '')
            ->implode(', ');
    }

    private function optionLabel(mixed $option): string
    {
        $option = $option instanceof Arrayable ? $option->toArray() : $option;

        if (is_array($option)) {
            return (string) (Arr::get($option, 'label') ?? Arr::get($option, 'value'));
        }

        return (string) $option;
    }

    private function renderJson(iterable $value): string
    {
        return json_encode(collect($value)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
