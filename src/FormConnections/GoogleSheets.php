<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\FormConnections\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsClient;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsException;
use Statamic\FormsPro\Http\Controllers\CP\GoogleSheetsConnectionController;
use Statamic\FormsPro\Http\Controllers\CP\GoogleSheetsTabsController;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class GoogleSheets extends Connection
{
    protected $developer = 'Statamic';

    public function description(): ?string
    {
        return __('forms-pro::messages.google_sheets_connection_description');
    }

    public function icon(): ?string
    {
        return File::get(__DIR__.'/../../resources/svg/google-sheets.svg');
    }

    public function finalized(Submission $submission): object|array
    {
        $config = $submission->form()->connections()->get('google_sheets', []);

        if (blank(Arr::get($config, 'spreadsheet')) || blank(Arr::get($config, 'tab'))) {
            return [];
        }

        return [new AppendRowToGoogleSheet($submission, $config)];
    }

    public function render(Form $form): VueComponent
    {
        $config = $form->connections()->get('google_sheets', []);
        $isConfigured = (bool) config('forms-pro.connections.google_sheets.credentials');
        $tabs = [];

        try {
            if ($isConfigured) {
                $serviceAccount = app(GoogleSheetsClient::class)->serviceAccountEmail();

                if ($spreadsheet = Arr::get($config, 'spreadsheet')) {
                    $tabs = static::tabs($spreadsheet);
                }
            }
        } catch (ConnectionException|GoogleSheetsException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form, $tabs);

        $fields = $blueprint->fields()
            ->addValues($config)
            ->preProcess();

        return VueComponent::render('google-sheets-connection', [
            'action' => cp_route('forms.connect.google_sheets.update', $form->handle()),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
            'isConfigured' => $isConfigured,
            'serviceAccount' => $serviceAccount ?? null,
            'apiError' => $apiError ?? null,
        ]);
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

    public static function blueprint(Form $form, array $tabs = []): \Statamic\Fields\Blueprint
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
                                        'instructions' => __('forms-pro::messages.google_sheets_connection_spreadsheet_instructions'),
                                        'placeholder' => 'https://docs.google.com/spreadsheets/d/…',
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'tab',
                                    'field' => [
                                        'type' => 'google_sheets_tabs',
                                        'display' => __('Tab'),
                                        'instructions' => __('forms-pro::messages.google_sheets_connection_tab_instructions'),
                                        'url' => cp_route('forms.connect.google_sheets.tabs', $form->handle()),
                                        'options' => $tabs,
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'fields',
                                    'field' => [
                                        'type' => 'checkboxes',
                                        'display' => __('Columns'),
                                        'instructions' => __('forms-pro::messages.google_sheets_connection_fields_instructions'),
                                        'options' => static::columns($form),
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
        $router->patch('/', [GoogleSheetsConnectionController::class, 'update'])->name('update');
    }
}
