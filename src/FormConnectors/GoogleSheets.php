<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connectors\Connector;
use Statamic\FormsPro\FormConnectors\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\FormsPro\FormConnectors\GoogleSheets\GoogleSheetsClient;
use Statamic\FormsPro\FormConnectors\GoogleSheets\GoogleSheetsException;
use Statamic\FormsPro\FormConnectors\GoogleSheets\Rules\SpreadsheetTab;
use Statamic\FormsPro\FormConnectors\GoogleSheets\Rules\SpreadsheetUrl;
use Statamic\FormsPro\Http\Controllers\CP\GoogleSheetsTabsController;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class GoogleSheets extends Connector
{
    protected $developer = 'Statamic';

    private ?string $apiError = null;

    public function description(): ?string
    {
        return __('forms-pro::messages.google_sheets_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/google-sheets.svg');
    }

    public function isConfigured(): bool
    {
        return (bool) config('statamic.forms-pro.connections.google_sheets.credentials');
    }

    protected function job(Submission $submission, array $connection): ?object
    {
        if (blank(Arr::get($connection, 'spreadsheet')) || blank(Arr::get($connection, 'tab'))) {
            return null;
        }

        return new AppendRowToGoogleSheet($submission, $connection);
    }

    public function render(): VueComponent
    {
        return VueComponent::render('google-sheets-connector', [
            ...$this->blueprintProps(),
            'isConfigured' => $this->isConfigured(),
            'serviceAccount' => $this->serviceAccount(),
            'apiError' => $this->apiError,
            'reservedFields' => $this->reservedFields(),
        ]);
    }

    private function reservedFields(): array
    {
        return $this->form()->formFields()->fields()->keys()
            ->intersect(array_keys(static::metaColumns()))
            ->values()
            ->all();
    }

    private function serviceAccount(): ?string
    {
        try {
            return $this->isConfigured() ? app(GoogleSheetsClient::class)->serviceAccountEmail() : null;
        } catch (ConnectionException|GoogleSheetsException $e) {
            $this->apiError = $e->getMessage();

            return null;
        }
    }

    protected function connectionMeta(array $connection): array
    {
        return [
            ...parent::connectionMeta($connection),
            'tab' => ['options' => $this->tabsFor(Arr::get($connection, 'spreadsheet'))],
        ];
    }

    private function tabsFor(?string $spreadsheet): array
    {
        if (! $spreadsheet || ! $this->isConfigured()) {
            return [];
        }

        try {
            return static::tabs($spreadsheet);
        } catch (ConnectionException|GoogleSheetsException $e) {
            $this->apiError = $e->getMessage();

            return [];
        }
    }

    public static function tabs(string $url): array
    {
        $client = app(GoogleSheetsClient::class);
        $id = static::extractSpreadsheetIdFromUrl($url);

        return Cache::remember(
            static::tabsCacheKey($client, $id),
            now()->addMinutes(5),
            fn (): array => $client->tabs($id)
        );
    }

    public static function forgetTabs(string $url): void
    {
        Cache::forget(static::tabsCacheKey(
            app(GoogleSheetsClient::class),
            static::extractSpreadsheetIdFromUrl($url)
        ));
    }

    private static function tabsCacheKey(GoogleSheetsClient $client, ?string $id): string
    {
        return "forms-pro.google-sheets.tabs.{$client->fingerprint()}.{$id}";
    }

    protected function connectionRules(): array
    {
        return [
            'spreadsheet' => [new SpreadsheetUrl],
            'tab' => ['integer', new SpreadsheetTab],
            'fields' => ['array'],
            'fields.*' => ['string', 'distinct', Rule::in(array_keys(static::columns($this->form())))],
        ];
    }

    protected function processConnection(array $connection): array
    {
        return parent::processConnection([
            ...$connection,
            'tab' => (int) Arr::get($connection, 'tab'),
        ]);
    }

    public function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::make()->setContents([
            'tabs' => [
                'main' => [
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'spreadsheet',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Spreadsheet'),
                                        'instructions' => __('forms-pro::messages.google_sheets_spreadsheet_instructions'),
                                        'placeholder' => 'https://docs.google.com/spreadsheets/d/…',
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'tab',
                                    'field' => [
                                        'type' => 'google_sheets_tabs',
                                        'display' => __('Tab'),
                                        'instructions' => __('forms-pro::messages.google_sheets_tab_instructions'),
                                        'url' => cp_route('forms.connect.google_sheets.tabs', $this->form()->handle()),
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'fields',
                                    'field' => [
                                        'type' => 'checkboxes',
                                        'display' => __('Columns'),
                                        'instructions' => __('forms-pro::messages.google_sheets_fields_instructions'),
                                        'options' => static::columns($this->form()),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public static function columns(Form $form): array
    {
        return $form->formFields()->fields()
            ->reject(fn ($field) => array_intersect($field->fieldtype()->categories(), ['information', 'structure']))
            ->mapWithKeys(fn ($field) => [$field->handle() => __($field->display())])
            ->merge(static::metaColumns())
            ->all();
    }

    public static function metaColumns(): array
    {
        return [
            'submitted_at' => __('Submitted At'),
            'submission_id' => __('Submission ID'),
        ];
    }

    public static function extractSpreadsheetIdFromUrl(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]{20,})#', $url, $matches)) {
            return $matches[1];
        }

        return preg_match('/^[a-zA-Z0-9-_]{20,}$/', $url) ? $url : null;
    }

    public function routes(Router $router): void
    {
        $router->get('tabs', GoogleSheetsTabsController::class)->name('tabs');
    }
}
