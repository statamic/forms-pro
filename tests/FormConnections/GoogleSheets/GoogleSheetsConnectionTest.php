<?php

namespace Tests\FormConnections\GoogleSheets;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnections\GoogleSheets;
use Statamic\FormsPro\FormConnections\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class GoogleSheetsConnectionTest extends TestCase
{
    use FakesGoogleServiceAccount;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.google_sheets.credentials' => $this->credentials()]);
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

        $this->assertEquals('google-sheets-connection', $component['name']);
        $this->assertEquals(cp_route('forms.connect.google_sheets.update', 'contact_us'), $component['props']['action']);
        $this->assertTrue($component['props']['isConfigured']);
        $this->assertEquals(self::SERVICE_ACCOUNT, $component['props']['serviceAccount']);
        $this->assertNull($component['props']['apiError']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_renders_the_saved_spreadsheet_address()
    {
        Http::fake([...$this->fakeAccessToken(), 'sheets.googleapis.com/*' => Http::response(['sheets' => []])]);

        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]);

        $props = (new GoogleSheets)->render($form)->toArray()['props'];

        $this->assertEquals(self::SPREADSHEET_URL, $props['values']['spreadsheet']);
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
        config(['forms-pro.connections.google_sheets.credentials' => null]);

        $props = (new GoogleSheets)->render($this->form())->toArray()['props'];

        $this->assertFalse($props['isConfigured']);
        $this->assertNull($props['serviceAccount']);
    }

    #[Test]
    public function it_renders_an_api_error_when_the_credentials_are_malformed()
    {
        config(['forms-pro.connections.google_sheets.credentials' => 'not-base64-encoded-json']);

        $props = (new GoogleSheets)->render($this->form())->toArray()['props'];

        $this->assertTrue($props['isConfigured']);
        $this->assertNull($props['serviceAccount']);
        $this->assertEquals('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.', $props['apiError']);
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
