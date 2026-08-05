<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Antlers;
use Statamic\Facades\Parse;
use Statamic\Fields\Value;
use Statamic\Sites\Site;
use Statamic\Support\Arr;
use Statamic\Support\Str;

use function Statamic\trans as __;

class MessageBuilder
{
    private const BLOCK_LIMIT = 50;

    private const FIELDS_PER_SECTION = 10;

    private const FIELD_TEXT_LIMIT = 2000;

    private const HEADING_LIMIT = 150;

    private const SECTION_TEXT_LIMIT = 3000;

    private const SHORT_VALUE_LENGTH = 80;

    public function __construct(private Submission $submission, private Site $site, private array $config) {}

    public function build(): array
    {
        return [
            'channel' => $this->config['channel'],
            'text' => $this->heading(),
            'blocks' => $this->blocks(),
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];
    }

    private function heading(): string
    {
        if ($heading = Arr::get($this->config, 'heading')) {
            return (string) Antlers::parse(Parse::config($heading), $this->submission->toAugmentedArray());
        }

        return __('forms-pro::messages.slack_connection_heading', [
            'form' => __($this->submission->form()->title()),
        ]);
    }

    private function blocks(): array
    {
        $fields = $this->fieldBlocks();
        $trailing = collect([$this->submissionBlock(), $this->contextBlock()])->filter();
        $available = self::BLOCK_LIMIT - $trailing->count() - 1;

        if ($fields->count() > $available) {
            $fields = $fields->take($available - 1)->push($this->truncationBlock());
        }

        return collect([$this->headingBlock()])->merge($fields)->merge($trailing)->all();
    }

    private function headingBlock(): array
    {
        return [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => Str::limit($this->heading(), self::HEADING_LIMIT - 1, '…'),
                'emoji' => true,
            ],
        ];
    }

    private function fieldBlocks(): Collection
    {
        if (! Arr::get($this->config, 'include_fields', true)) {
            return collect();
        }

        $blocks = collect();
        $short = collect();

        foreach ($this->renderedFields() as $field) {
            if ($this->isShort($field['text'])) {
                $short->push($field);

                continue;
            }

            $blocks = $blocks->merge($this->fieldsSections($short))->push($this->textSection($field));
            $short = collect();
        }

        return $blocks->merge($this->fieldsSections($short))->values();
    }

    private function renderedFields(): Collection
    {
        $selected = Arr::get($this->config, 'fields');
        $excluded = $this->submission->form()->formFields()->fields()
            ->filter(fn ($field) => array_intersect($field->fieldtype()->categories(), ['information', 'structure']))
            ->keys();

        return collect(Arr::except($this->submission->toAugmentedArray(), ['id', 'date', 'form']))
            ->filter(fn ($value) => $value instanceof Value)
            ->reject(fn (Value $value, string $handle) => $excluded->contains($handle))
            ->when($selected, fn (Collection $fields) => $fields->only($selected))
            ->map(fn (Value $value) => [
                'display' => $value->field()->display(),
                'text' => $this->renderValue($value),
            ])
            ->reject(fn (array $field) => blank($field['text']))
            ->values();
    }

    private function renderValue(Value $value): string
    {
        $field = $value->field();
        $type = $field->type();
        $value = $value->value();

        return match (true) {
            $type === 'assets', $type === 'form_upload' && $field->get('store') => $this->renderAssets($value),
            in_array($type, ['files', 'form_upload']) => $this->renderFilenames($value),
            in_array($type, ['select', 'radio', 'checkboxes', 'dictionary']) => $this->renderOptions($value),
            is_iterable($value) => $this->renderJson($value),
            default => $this->escape((string) $value),
        };
    }

    private function renderAssets(mixed $value): string
    {
        $assets = $value instanceof Asset ? collect([$value]) : collect($value?->get());

        return $assets->map(fn (Asset $asset) => $this->renderAsset($asset))->implode(', ');
    }

    private function renderAsset(Asset $asset): string
    {
        $filename = $this->escape($asset->basename());

        return ($url = $asset->absoluteUrl()) ? "<{$url}|{$filename}>" : $filename;
    }

    private function renderFilenames(mixed $value): string
    {
        return collect(Arr::wrap($value))
            ->map(fn ($file) => $this->escape(basename((string) $file)))
            ->implode(', ');
    }

    private function renderOptions(mixed $value): string
    {
        return collect(is_iterable($value) ? $value : [$value])
            ->map(fn ($option) => $this->escape($this->optionLabel($option)))
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
        $json = json_encode(collect($value)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '`'.$this->escape($json).'`';
    }

    private function isShort(string $text): bool
    {
        return ! Str::contains($text, "\n") && Str::length($text) <= self::SHORT_VALUE_LENGTH;
    }

    private function fieldsSections(Collection $fields): Collection
    {
        return $fields
            ->chunk(self::FIELDS_PER_SECTION)
            ->map(fn (Collection $chunk) => [
                'type' => 'section',
                'fields' => $chunk
                    ->map(fn (array $field) => $this->mrkdwn($field, self::FIELD_TEXT_LIMIT))
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    private function textSection(array $field): array
    {
        return [
            'type' => 'section',
            'text' => $this->mrkdwn($field, self::SECTION_TEXT_LIMIT),
        ];
    }

    private function mrkdwn(array $field, int $limit): array
    {
        $label = '*'.$this->escape(__($field['display']))."*\n";

        return [
            'type' => 'mrkdwn',
            'text' => Str::limit($label.$field['text'], $limit - 1, '…'),
        ];
    }

    private function submissionBlock(): ?array
    {
        if (! Arr::get($this->config, 'link_to_submission', true)) {
            return null;
        }

        return [
            'type' => 'actions',
            'elements' => [[
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => __('forms-pro::messages.slack_connection_view_submission')],
                'url' => cp_route('forms.submissions.show', [$this->submission->form()->handle(), $this->submission->id()]),
            ]],
        ];
    }

    private function contextBlock(): array
    {
        $date = $this->submission->date();

        return [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => collect([
                    $this->escape(__($this->submission->form()->title())),
                    $this->site->handle(),
                    '<!date^'.$date->timestamp.'^{date_short_pretty} {time}|'.$date->toDayDateTimeString().'>',
                ])->implode(' · '),
            ]],
        ];
    }

    private function truncationBlock(): array
    {
        return [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => '_'.__('forms-pro::messages.slack_connection_truncated').'_',
            ]],
        ];
    }

    private function escape(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }
}
