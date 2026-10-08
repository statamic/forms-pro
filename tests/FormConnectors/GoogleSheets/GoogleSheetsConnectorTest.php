<?php

namespace Tests\FormConnectors\GoogleSheets;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\GoogleSheets;
use Statamic\FormsPro\FormConnectors\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\FakesConnectorApis;
use Tests\TestCase;

class GoogleSheetsConnectorTest extends TestCase
{
    use FakesConnectorApis, PreventsSavingStacheItemsToDisk;
    use FakesGoogleServiceAccount;

    private const string OTHER_SPREADSHEET = '9ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210zyxwvu';

    private const string OTHER_SPREADSHEET_URL = 'https://docs.google.com/spreadsheets/d/'.self::OTHER_SPREADSHEET.'/edit';

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.google_sheets.credentials' => $this->credentials()]);
    }

    #[Test]
    public function it_returns_a_job_for_the_configured_spreadsheet()
    {
        $form = $this->form([['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL, 'tab' => 184]]);

        $jobs = $this->finalized($form->makeSubmission()->data(['name' => 'Jack Black']));

        $this->assertCount(1, $jobs);
        $this->assertContainsOnlyInstancesOf(AppendRowToGoogleSheet::class, $jobs);
        $this->assertEquals(self::SPREADSHEET_URL, $jobs[0]->config['spreadsheet']);
    }

    #[Test]
    public function it_returns_a_job_for_the_first_tab()
    {
        $form = $this->form([['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]]);

        $this->assertCount(1, $this->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_returns_no_jobs_when_the_form_isnt_connected()
    {
        $this->assertEmpty($this->finalized($this->form()->makeSubmission()));
    }

    #[Test]
    public function it_returns_no_jobs_without_a_spreadsheet()
    {
        $form = $this->form([['id' => 'abc', 'tab' => 184]]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_returns_no_jobs_without_a_tab()
    {
        $form = $this->form([['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL]]);

        $this->assertEmpty($this->finalized($form->makeSubmission()->data([])));
    }

    #[Test]
    public function it_renders_the_vue_component()
    {
        $this->fakeHttp();

        $component = (new GoogleSheets)->render($this->form())->toArray();

        $this->assertEquals('google-sheets-connector', $component['name']);
        $this->assertEquals(['blueprint', 'meta', 'defaults', 'isConfigured', 'serviceAccount', 'apiError'], array_keys($component['props']));
        $this->assertTrue($component['props']['isConfigured']);
        $this->assertEquals(self::SERVICE_ACCOUNT, $component['props']['serviceAccount']);
        $this->assertNull($component['props']['apiError']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_the_saved_spreadsheets_tabs_so_none_are_fetched_on_load()
    {
        $this->fakeHttp([...$this->fakeAccessToken(), 'sheets.googleapis.com/*' => Http::response(['sheets' => [
            ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026']],
            ['properties' => ['sheetId' => 184, 'title' => 'Archive']],
        ]])]);

        $form = $this->form([['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]]);

        $props = (new GoogleSheets)->render($form)->toArray()['props'];

        $this->assertEquals([0 => 'Leads 2026', 184 => 'Archive'], $props['meta']['abc']['tab']['options']);
        $this->assertEquals([], $props['defaults']['meta']['tab']['options']);
    }

    #[Test]
    public function it_renders_the_tabs_of_each_connections_spreadsheet()
    {
        $this->fakeTwoSpreadsheets();

        $form = $this->form([
            ['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0],
            ['id' => 'def', 'spreadsheet' => self::OTHER_SPREADSHEET_URL, 'tab' => 7],
        ]);

        $meta = (new GoogleSheets)->render($form)->toArray()['props']['meta'];

        $this->assertEquals([0 => 'Leads 2026', 184 => 'Archive'], $meta['abc']['tab']['options']);
        $this->assertEquals([7 => 'Signups'], $meta['def']['tab']['options']);
    }

    #[Test]
    public function it_renders_no_tabs_when_no_spreadsheet_is_saved()
    {
        $this->fakeHttp();

        $props = (new GoogleSheets)->render($this->form([['id' => 'abc', 'tab' => 0]]))->toArray()['props'];

        $this->assertEquals([], $props['meta']['abc']['tab']['options']);
        $this->assertEquals([], $props['defaults']['meta']['tab']['options']);

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
        ], collect($props['defaults']['meta']['fields']['options'])->pluck('label', 'value')->all());
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
        $form = $this->form([['id' => 'abc', 'spreadsheet' => self::SPREADSHEET_URL, 'tab' => 184, 'fields' => ['email', 'submitted_at']]]);

        $configs = (new GoogleSheets)->preProcess($form->connections()->get('google_sheets'), $form);

        $this->assertEquals([[
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
            'id' => 'abc',
            'enabled' => true,
            'conditions' => [],
        ]], $configs);
    }

    #[Test]
    public function it_validates_the_config()
    {
        $this->fake();

        $validator = Validator::make([[
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ]], (new GoogleSheets)->rules($this->form()));

        $this->assertTrue($validator->passes());
    }

    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_configs(array $config, array $errors)
    {
        $this->fake();

        $validator = Validator::make([$config], (new GoogleSheets)->rules($this->form()));

        $this->assertTrue($validator->fails());

        foreach ($errors as $key) {
            $this->assertTrue($validator->errors()->has('0.'.$key));
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
    public function it_validates_each_tab_against_its_own_connections_spreadsheet()
    {
        $this->fakeTwoSpreadsheets();

        $rules = (new GoogleSheets)->rules($this->form());

        $this->assertTrue(Validator::make([
            ['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 184],
            ['spreadsheet' => self::OTHER_SPREADSHEET_URL, 'tab' => 7],
        ], $rules)->passes());

        $validator = Validator::make([
            ['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 7],
            ['spreadsheet' => self::OTHER_SPREADSHEET_URL, 'tab' => 184],
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertEquals(['0.tab', '1.tab'], $validator->errors()->keys());
    }

    #[Test]
    public function it_rejects_a_spreadsheet_it_cant_open_in_another_connection()
    {
        $this->fakeHttp([
            ...$this->fakeAccessToken(),
            'sheets.googleapis.com/v4/spreadsheets/'.self::SPREADSHEET.'*' => Http::response(['sheets' => [
                ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026']],
            ]]),
            'sheets.googleapis.com/v4/spreadsheets/'.self::OTHER_SPREADSHEET.'*' => Http::response([
                'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        try {
            Validator::make([
                ['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0],
                ['spreadsheet' => self::OTHER_SPREADSHEET_URL, 'tab' => 0],
            ], (new GoogleSheets)->rules($this->form()))->validate();

            $this->fail('A validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertEquals(['1.spreadsheet'], array_keys($e->errors()));
        }
    }

    #[Test]
    public function it_rejects_a_spreadsheet_it_cant_open()
    {
        $this->fake(['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        try {
            Validator::make([[
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 0,
            ]], (new GoogleSheets)->rules($this->form()))->validate();

            $this->fail('A validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertEquals(["Couldn't open that spreadsheet. Check the address and make sure it's shared with "
                .self::SERVICE_ACCOUNT.' as an Editor.'], $e->errors()['0.spreadsheet']);
        }
    }

    #[Test]
    public function it_rejects_a_form_with_a_field_named_after_a_column_it_adds()
    {
        $this->fakeHttp();

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
        $this->assertEquals([[
            'id' => 'abc',
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ]], (new GoogleSheets)->process([[
            'id' => 'abc',
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => '184',
            'fields' => ['email', 'submitted_at'],
        ]], $this->form()));
    }

    #[Test]
    public function it_stores_the_address_exactly_as_it_was_pasted()
    {
        $pasted = 'https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET.'/edit?gid=184#gid=184';

        $configs = (new GoogleSheets)->process([['id' => 'abc', 'spreadsheet' => $pasted, 'tab' => 0]], $this->form());

        $this->assertEquals([['id' => 'abc', 'spreadsheet' => $pasted, 'tab' => 0]], $configs);
    }

    #[Test]
    public function it_removes_null_values_from_the_config()
    {
        $this->assertEquals([[
            'id' => 'abc',
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
        ]], (new GoogleSheets)->process([[
            'id' => 'abc',
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => null,
        ]], $this->form()));
    }

    private function fake(array $overrides = []): void
    {
        $this->fakeHttp([
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

    private function fakeTwoSpreadsheets(): void
    {
        $this->fakeHttp([
            ...$this->fakeAccessToken(),
            'sheets.googleapis.com/v4/spreadsheets/'.self::SPREADSHEET.'*' => Http::response(['sheets' => [
                ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026']],
                ['properties' => ['sheetId' => 184, 'title' => 'Archive']],
            ]]),
            'sheets.googleapis.com/v4/spreadsheets/'.self::OTHER_SPREADSHEET.'*' => Http::response(['sheets' => [
                ['properties' => ['sheetId' => 7, 'title' => 'Signups']],
            ]]),
        ]);
    }

    private function form(array $connections = [], ?array $fields = null): FormContract
    {
        $form = Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => $fields ?? [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
            ],
        ]);

        if ($connections) {
            $form->connections(['google_sheets' => $connections]);
        }

        return tap($form)->save();
    }

    private function finalized(Submission $submission): array
    {
        return (new GoogleSheets)->setConfig($submission->form()->connections()->get(GoogleSheets::handle(), []))->finalized($submission);
    }
}
