<?php

namespace Statamic\FormsPro\FormConnections\GoogleSheets;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Statamic\Contracts\Forms\Submission;
use Statamic\FormsPro\FormConnections\GoogleSheets;
use Statamic\Support\Arr;

class AppendRowToGoogleSheet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int LOCK_SECONDS = 45;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(GoogleSheetsClient $sheets): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;
        $values = (new RowBuilder($submission, $this->config))->build();

        if (! $values) {
            return;
        }

        $handles = array_keys($values);

        try {
            $title = $this->sheet($sheets)['title'];
            $headers = $sheets->headerRow($this->spreadsheet(), $title);
            $columns = $this->columns($headers, $handles);

            if (array_diff($handles, array_keys($columns))) {
                [$columns, $headers] = $this->claimColumns($sheets, $title, $handles);
            }

            $sheets->append($this->spreadsheet(), $title, $this->row($values, $columns, $this->anchor($headers)));
        } catch (GoogleSheetsException $e) {
            if (! $e->isRetryable()) {
                Cache::forget($this->cacheKey($sheets));
            }

            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                default => throw $e,
            };
        }
    }

    /**
     * Titles get renamed, so they're resolved from the immutable sheet ID on
     * every append. Cached, because that's rare enough that a stale title is
     * worth one clean failure.
     */
    private function sheet(GoogleSheetsClient $sheets): array
    {
        return Cache::remember($this->cacheKey($sheets), now()->addMinutes(5), fn () => $this->fetchSheet($sheets));
    }

    private function fetchSheet(GoogleSheetsClient $sheets): array
    {
        $properties = collect($sheets->spreadsheet($this->spreadsheet())['sheets'] ?? [])
            ->pluck('properties')
            ->firstWhere('sheetId', $this->tab());

        throw_unless($properties, new GoogleSheetsException(
            "The tab this form writes to no longer exists in the spreadsheet [{$this->spreadsheet()}]."
        ));

        return $properties;
    }

    /**
     * Header name to column index, for every header in the sheet — including
     * the ones nobody here put there, so a new column lands after them all.
     */
    private function columns(array $headers, array $handles): array
    {
        $columns = [];
        $duplicates = [];

        foreach ($headers as $index => $header) {
            if (($header = trim((string) $header)) === '') {
                continue;
            }

            if (isset($columns[$header])) {
                $duplicates[] = $header;

                continue;
            }

            $columns[$header] = $index;
        }

        if ($ambiguous = array_intersect($duplicates, $handles)) {
            throw new GoogleSheetsException(
                'The sheet has more than one ['.implode('], [', array_unique($ambiguous)).'] column, so there\'s no telling which one to write to.'
            );
        }

        return $columns;
    }

    /**
     * Reading the headers, widening the grid and writing new ones is a
     * read-modify-write, so only one job may do it at a time. Whoever loses
     * comes back once the winner has finished.
     */
    private function claimColumns(GoogleSheetsClient $sheets, string $title, array $handles): array
    {
        $lock = Cache::lock("forms-pro.google-sheets.columns.{$this->spreadsheet()}.{$this->tab()}", self::LOCK_SECONDS);

        throw_unless($lock->get(), new GoogleSheetsException('Another submission is adding columns to this tab.', 5));

        try {
            return $this->addHeaders($sheets, $title, $handles);
        } finally {
            $lock->release();
        }
    }

    private function addHeaders(GoogleSheetsClient $sheets, string $title, array $handles): array
    {
        $headers = $sheets->headerRow($this->spreadsheet(), $title);
        $columns = $this->columns($headers, $handles);
        $missing = array_values(array_diff($handles, array_keys($columns)));

        if (! $missing) {
            return [$columns, $headers];
        }

        $first = $columns ? max($columns) + 1 : 0;
        $width = Arr::get($this->fetchSheet($sheets), 'gridProperties.columnCount', 0);

        if ($first + count($missing) > $width) {
            $sheets->widenGrid($this->spreadsheet(), $this->tab(), $first + count($missing) - $width);
        }

        $sheets->addHeaders($this->spreadsheet(), $title, $first, $missing);

        $headers = array_pad($headers, $first + count($missing), '');
        array_splice($headers, $first, count($missing), $missing);

        return [
            $columns + array_combine($missing, range($first, $first + count($missing) - 1)),
            $headers,
        ];
    }

    /**
     * Google appends to the last unbroken run of cells in the range, so a blank
     * header partway along row 1 starts a new table and everything before it is
     * ignored. That's where the row has to begin.
     */
    private function anchor(array $headers): int
    {
        $headers = array_map(fn ($header) => trim((string) $header), $headers);

        while ($headers && end($headers) === '') {
            array_pop($headers);
        }

        $blanks = array_keys($headers, '', true);

        return $blanks ? max($blanks) + 1 : 0;
    }

    /**
     * Every cell from the anchor to the last column, so that a value can't
     * drift into the column beside it.
     */
    private function row(array $values, array $columns, int $anchor): array
    {
        $columns = Arr::only($columns, array_keys($values));

        if (min($columns) < $anchor) {
            throw new GoogleSheetsException(
                'Row 1 has an empty cell before ['.implode('], [', array_keys($columns, min($columns), true)).'], and Google only adds rows to the columns after the last empty one. Fill the gap in, or move these columns to the end.'
            );
        }

        $row = array_fill(0, max($columns) + 1 - $anchor, '');

        foreach ($values as $handle => $value) {
            $row[$columns[$handle] - $anchor] = $value;
        }

        return $row;
    }

    private function spreadsheet(): string
    {
        return GoogleSheets::extractSpreadsheetIdFromUrl($this->config['spreadsheet']);
    }

    private function tab(): int
    {
        return (int) $this->config['tab'];
    }

    private function cacheKey(GoogleSheetsClient $sheets): string
    {
        return "forms-pro.google-sheets.sheet.{$sheets->fingerprint()}.{$this->spreadsheet()}.{$this->tab()}";
    }
}
