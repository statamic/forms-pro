<?php

namespace Statamic\FormsPro\FormConnectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function render(Form $form): VueComponent
    {
        $isConfigured = $this->isConfigured();

        try {
            $serviceAccount = $isConfigured ? app(GoogleSheetsClient::class)->serviceAccountEmail() : null;
        } catch (ConnectionException|GoogleSheetsException $e) {
            $apiError = $e->getMessage();
        }

        $blueprint = static::blueprint($form);
        $fields = $blueprint->fields()->preProcess();

        $meta = collect($form->connections()->get('google_sheets'))
            ->mapWithKeys(function (array $connection) use ($fields, $isConfigured, &$apiError): array {
                $tabs = [];

                try {
                    if ($isConfigured && ($spreadsheet = Arr::get($connection, 'spreadsheet'))) {
                        $tabs = static::tabs($spreadsheet);
                    }
                } catch (ConnectionException|GoogleSheetsException $e) {
                    $apiError = $e->getMessage();
                }

                return [$connection['id'] => [
                    ...$fields->addValues($connection)->preProcess()->meta()->all(),
                    'tab' => ['options' => $tabs],
                ]];
            })
            ->all();

        return VueComponent::render('google-sheets-connector', [
            'blueprint' => $blueprint->toPublishArray(),
            'meta' => $meta,
            'defaults' => [
                'values' => $fields->values()->all(),
                'meta' => [...$fields->meta()->all(), 'tab' => ['options' => []]],
            ],
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

    protected function preProcessConnection(array $connection, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues($connection)
            ->preProcess()
            ->values()
            ->all();
    }

    protected function connectionRules(Form $form): array
    {
        $reserved = $form->formFields()->fields()->keys()->intersect(array_keys(static::metaColumns()));

        throw_if($reserved->isNotEmpty(), ValidationException::withMessages([
            'fields' => __('forms-pro::validation.google_sheets_reserved_column', ['field' => $reserved->first()]),
        ]));

        return [
            'spreadsheet' => ['required', new SpreadsheetUrl],
            'tab' => ['required', 'integer', new SpreadsheetTab],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'distinct', Rule::in(array_keys(static::columns($form)))],
        ];
    }

    protected function processConnection(array $connection, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues([
                ...$connection,
                'tab' => (int) Arr::get($connection, 'tab'),
            ])
            ->process()
            ->values()
            ->all();
    }

    public static function blueprint(Form $form): \Statamic\Fields\Blueprint
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
                                        'url' => cp_route('forms.connect.google_sheets.tabs', $form->handle()),
                                        'validate' => ['required'],
                                    ],
                                ],
                                [
                                    'handle' => 'fields',
                                    'field' => [
                                        'type' => 'checkboxes',
                                        'display' => __('Columns'),
                                        'instructions' => __('forms-pro::messages.google_sheets_fields_instructions'),
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
    }
}
