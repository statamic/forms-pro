<?php

namespace Statamic\FormsPro\FormConnections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Forms\Form;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Blueprint;
use Statamic\Forms\Connections\Connection;
use Statamic\FormsPro\FormConnections\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsClient;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsException;
use Statamic\FormsPro\FormConnections\GoogleSheets\Rules\SpreadsheetTab;
use Statamic\FormsPro\FormConnections\GoogleSheets\Rules\SpreadsheetUrl;
use Statamic\FormsPro\Http\Controllers\CP\GoogleSheetsTabsController;
use Statamic\Support\Arr;
use Statamic\Support\VueComponent;

use function Statamic\trans as __;

class GoogleSheets extends Connection
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
        $tabs = [];
        $config = $form->connections()->get('google_sheets', []);
        $isConfigured = $this->isConfigured();

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
            'blueprint' => $blueprint->toPublishArray(),
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

    public function preProcess(array $config, Form $form): array
    {
        return static::blueprint($form)->fields()
            ->addValues($config)
            ->preProcess()
            ->values()
            ->all();
    }

    public function rules(Form $form): array
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

    public function process(array $data, Form $form): array
    {
        $config = static::blueprint($form)->fields()
            ->addValues([
                ...Arr::removeNullValues($data),
                'tab' => (int) Arr::get($data, 'tab'),
            ])
            ->process()
            ->values()
            ->all();

        return Arr::removeNullValues($config);
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
                                        'options' => $tabs,
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
