<?php

namespace Tests\FormConnectors\GoogleSheets;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\GoogleSheets;
use Statamic\FormsPro\FormConnectors\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class GoogleSheetsConnectorTest extends TestCase
{
    use FakesGoogleServiceAccount;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.google_sheets.credentials' => $this->credentials()]);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_spreadsheet()
    {
        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 184]);

        $jobs = (new GoogleSheets)->finalized($form->makeSubmission()->data(['name' => 'Jack Black']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(AppendRowToGoogleSheet::class, $jobs);
        $this->assertEquals(self::SPREADSHEET_URL, $jobs[0]->config['spreadsheet']);
    }

    #[Test]
    public function it_returns_a_job_for_the_first_tab()
    {
        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]);

        $this->assertCount(1, (new GoogleSheets)->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected()
    {
        $this->assertEmpty((new GoogleSheets)->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_returns_no_jobs_without_a_spreadsheet()
    {
        $form = $this->form(['tab' => 184]);

        $this->assertEmpty((new GoogleSheets)->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_returns_no_jobs_without_a_tab()
    {
        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL]);

        $this->assertEmpty((new GoogleSheets)->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_renders_the_vue_component()
    {
        Http::fake();

        $component = (new GoogleSheets)->render($this->form())->toArray();

        $this->assertEquals('google-sheets-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'isConfigured', 'serviceAccount', 'apiError'], array_keys($component['props']));
        $this->assertTrue($component['props']['isConfigured']);
        $this->assertEquals(self::SERVICE_ACCOUNT, $component['props']['serviceAccount']);
        $this->assertNull($component['props']['apiError']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_the_saved_spreadsheets_tabs_so_none_are_fetched_on_load()
    {
        Http::fake([...$this->fakeAccessToken(), 'sheets.googleapis.com/*' => Http::response(['sheets' => [
            ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026']],
            ['properties' => ['sheetId' => 184, 'title' => 'Archive']],
        ]])]);

        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]);

        $fields = (new GoogleSheets)->render($form)->toArray()['props']['blueprint']['tabs'][0]['sections'][0]['fields'];

        $this->assertEquals([0 => 'Leads 2026', 184 => 'Archive'], collect($fields)->firstWhere('handle', 'tab')['options']);
    }

    #[Test]
    public function it_renders_no_tabs_when_no_spreadsheet_is_saved()
    {
        Http::fake();

        $fields = (new GoogleSheets)->render($this->form())->toArray()['props']['blueprint']['tabs'][0]['sections'][0]['fields'];

        $this->assertEmpty(collect($fields)->firstWhere('handle', 'tab')['options']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_offers_every_field_and_the_meta_columns_as_columns()
    {
        $form = $this->form(fields: [
            ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ['handle' => 'name', 'field' => ['type' => 'text', 'display' => 'Name']],
        ]);

        $props = (new GoogleSheets)->render($form)->toArray()['props'];

        $this->assertEquals([
            'name' => 'Name',
            'submitted_at' => 'Submitted At',
            'submission_id' => 'Submission ID',
        ], collect($props['meta']['fields']['options'])->pluck('label', 'value')->all());
    }

    #[Test]
    public function it_renders_the_setup_instructions_when_unconfigured()
    {
        config(['statamic.forms-pro.connections.google_sheets.credentials' => null]);

        $props = (new GoogleSheets)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        $this->assertNull($props['serviceAccount']);
    }

    #[Test]
    public function it_renders_an_api_error_when_the_credentials_are_malformed()
    {
        config(['statamic.forms-pro.connections.google_sheets.credentials' => 'not-base64-encoded-json']);

        $props = (new GoogleSheets)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['serviceAccount']);
        $this->assertEquals('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.', $props['apiError']);
    }

    #[Test]
    public function it_pre_processes_the_config()
    {
        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 184, 'fields' => ['email', 'submitted_at']]);

        $config = (new GoogleSheets)->preProcess($form->connections()->get('google_sheets'), $form);

        $this->assertEquals([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ], $config);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $this->fake();

        $validator = Validator::make([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ], (new GoogleSheets)->rules($this->form()));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $this->fake();

        $validator = Validator::make($config, (new GoogleSheets)->rules($this->form()));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has($key));
        }
    }

    public static function invalidConfigs(): array
    {
        return [
            'missing spreadsheet' => [['tab' => 0], ['spreadsheet']],
            'spreadsheet that isnt an address' => [['spreadsheet' => 'nope', 'tab' => 0], ['spreadsheet']],
            'missing tab' => [['spreadsheet' => self::SPREADSHEET], ['tab']],
            'tab that isnt in the spreadsheet' => [['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 999], ['tab']],
            'tab that isnt a number' => [['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 'Archive'], ['tab']],
            'unknown column' => [
                ['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0, 'fields' => ['nope']],
                ['fields.0'],
            ],
            'the same column twice' => [
                ['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0, 'fields' => ['email', 'email']],
                ['fields.0', 'fields.1'],
            ],
        ];
    }

    #[Test]
    public function it_rejects_a_spreadsheet_it_cant_open()
    {
        $this->fake(['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        try {
            Validator::make([
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 0,
            ], (new GoogleSheets)->rules($this->form()))->validate();

            $this->fail('A validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertEquals(["Couldn't open that spreadsheet. Check the address and make sure it's shared with "
                .self::SERVICE_ACCOUNT.' as an Editor.'], $e->errors()['spreadsheet']);
        }
    }

    #[Test]
    public function it_rejects_a_form_with_a_field_named_after_a_column_it_adds()
    {
        Http::fake();

        $form = $this->form(fields: [
            ['handle' => 'email', 'field' => ['type' => 'text']],
            ['handle' => 'submitted_at', 'field' => ['type' => 'date']],
        ]);

        try {
            (new GoogleSheets)->rules($form);

            $this->fail('A validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertEquals(['This form has a field called submitted_at, which clashes with a column this connection adds. Rename the field to use this connection.'], $e->errors()['fields']);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function it_processes_the_config()
    {
        $this->assertEquals([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ], (new GoogleSheets)->process([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => '184',
            'fields' => ['email', 'submitted_at'],
        ], $this->form()));
    }

    #[Test]
    public function it_stores_the_address_exactly_as_it_was_pasted()
    {
        $pasted = 'https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET.'/edit?gid=184#gid=184';

        $config = (new GoogleSheets)->process(['spreadsheet' => $pasted, 'tab' => 0], $this->form());

        $this->assertEquals(['spreadsheet' => $pasted, 'tab' => 0], $config);
    }

    #[Test]
    public function it_removes_null_values_from_the_config()
    {
        $this->assertEquals([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
        ], (new GoogleSheets)->process([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => null,
        ], $this->form()));
    }

    private function fake(array $overrides = []): void
    {
        Http::fake([
            ...$this->fakeAccessToken(),
            'sheets.googleapis.com/*' => Http::response([
                'properties' => ['title' => 'Q3 Leads'],
                'sheets' => [
                    ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026']],
                    ['properties' => ['sheetId' => 184, 'title' => 'Archive']],
                ],
            ]),
            ...$overrides,
        ]);
    }

    private function form(?array $config = null, ?array $fields = null): FormContract
    {
        $form = Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => $fields ?? [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
            ],
        ]);

        if ($config) {
            $form->connections(['google_sheets' => $config]);
        }

        return tap($form)->save();
    }
}
