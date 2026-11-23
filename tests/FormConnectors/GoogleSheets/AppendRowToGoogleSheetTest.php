<?php

namespace Tests\FormConnectors\GoogleSheets;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Form;
use Statamic\FormsPro\FormConnectors\GoogleSheets\AppendRowToGoogleSheet;
use Statamic\FormsPro\FormConnectors\GoogleSheets\GoogleSheetsClient;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\FormConnectors\AssertsFailedJobs;
use Tests\TestCase;

class AppendRowToGoogleSheetTest extends TestCase
{
    use AssertsFailedJobs;
    use FakesGoogleServiceAccount;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.forms-pro.connections.google_sheets.credentials' => $this->credentials()]);
    }

    #[Test]
    public function it_appends_the_row_to_the_tab()
    {
        $this->fake(headers: ['name', 'email']);

        $this->handle($this->form(), ['name' => 'Jack Black', 'email' => 'jack@example.com'], ['fields' => ['name', 'email']]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/'.self::SPREADSHEET
                .'/values/%27Leads%202026%27%211%3A1:append?valueInputOption=RAW&insertDataOption=INSERT_ROWS'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer ya29.token')
                && $request['values'] === [['Jack Black', 'jack@example.com']];
        });
    }

    #[Test]
    #[DataProvider('valueProvider')]
    public function it_serialises_values(array $field, mixed $value, string $expected)
    {
        $form = $this->form([['handle' => 'value', 'field' => $field]]);

        $this->assertEquals([$expected], $this->append($form, ['value' => $value], ['fields' => ['value']]));
    }

    public static function valueProvider(): array
    {
        $options = ['sales' => 'Sales Enquiry', 'support' => 'Support Request'];

        return [
            'text' => [['type' => 'text'], 'Jack Black', 'Jack Black'],
            'blank' => [['type' => 'text'], null, ''],
            'zero' => [['type' => 'integer'], 0, '0'],
            'select' => [['type' => 'select', 'options' => $options], 'sales', 'Sales Enquiry'],
            'select without a label' => [['type' => 'select', 'options' => ['sales' => null]], 'sales', 'sales'],
            'multiple select' => [['type' => 'select', 'multiple' => true, 'options' => $options], ['sales', 'support'], 'Sales Enquiry, Support Request'],
            'checkboxes' => [['type' => 'checkboxes', 'options' => $options], ['sales'], 'Sales Enquiry'],
            'yes_no' => [['type' => 'yes_no'], 'yes', 'Yes'],
            'image_choice' => [['type' => 'image_choice', 'options' => [['key' => 'spring', 'label' => 'Spring'], ['key' => 'summer', 'label' => 'Summer']]], 'summer', 'Summer'],
            'multiple image_choice' => [['type' => 'image_choice', 'multiple' => true, 'options' => [['key' => 'spring', 'label' => 'Spring'], ['key' => 'summer', 'label' => 'Summer']]], ['summer', 'spring'], 'Summer, Spring'],
            'image_choice without a label' => [['type' => 'image_choice', 'options' => [['key' => 'spring']]], 'spring', 'spring'],
            'ranking' => [['type' => 'ranking', 'options' => $options], ['support', 'sales'], 'Support Request, Sales Enquiry'],
            'ranking without a label' => [['type' => 'ranking', 'options' => ['sales' => null]], ['sales'], 'sales'],
            'array' => [['type' => 'array'], ['one' => 'two'], '{"one":"two"}'],
            'unstored upload' => [['type' => 'form_upload'], ['1785861616.23/cv/cv.pdf'], 'cv.pdf'],
        ];
    }

    #[Test]
    public function it_serialises_stored_uploads_as_links()
    {
        Storage::fake('test', ['url' => 'http://localhost/assets']);
        Storage::disk('test')->put('cv.pdf', '');
        AssetContainer::make('documents')->disk('test')->save();

        $form = $this->form([['handle' => 'value', 'field' => ['type' => 'form_upload', 'store' => true, 'container' => 'documents']]]);

        $this->assertEquals(['http://localhost/assets/cv.pdf'], $this->append($form, ['value' => ['cv.pdf']], ['fields' => ['value']]));
    }

    #[Test]
    public function it_serialises_stored_uploads_in_a_private_container_as_filenames()
    {
        Storage::fake('test', ['url' => null]);
        Storage::disk('test')->put('cv.pdf', '');
        AssetContainer::make('documents')->disk('test')->save();

        $form = $this->form([['handle' => 'value', 'field' => ['type' => 'form_upload', 'store' => true, 'container' => 'documents']]]);

        $this->assertEquals(['cv.pdf'], $this->append($form, ['value' => ['cv.pdf']], ['fields' => ['value']]));
    }

    #[Test]
    public function it_includes_every_field_and_both_meta_columns_by_default()
    {
        $submission = $this->form()->makeSubmission()->data(['name' => 'Jack Black', 'email' => 'jack@example.com']);

        $this->fake();

        (new AppendRowToGoogleSheet($submission, $this->config()))->handle(app(GoogleSheetsClient::class));

        $this->assertEquals(['name', 'email', 'submitted_at', 'submission_id'], $this->headersWritten());
        $this->assertEquals([
            'Jack Black',
            'jack@example.com',
            $submission->date()->toIso8601String(),
            $submission->id(),
        ], $this->appendedRow());
    }

    #[Test]
    public function it_excludes_information_fields()
    {
        $form = $this->form([
            ['handle' => 'about_you', 'field' => ['type' => 'heading', 'display' => 'About You']],
            ['handle' => 'name', 'field' => ['type' => 'text']],
        ]);

        $this->append($form, ['about_you' => 'About You', 'name' => 'Jack Black']);

        $this->assertEquals(['name', 'submitted_at', 'submission_id'], $this->headersWritten());
    }

    #[Test]
    public function it_excludes_display_only_fields()
    {
        $form = $this->form([
            ['handle' => 'intro', 'field' => ['type' => 'paragraph', 'display' => 'Intro']],
            ['handle' => 'notice', 'field' => ['type' => 'banner', 'display' => 'Notice']],
            ['handle' => 'gap', 'field' => ['type' => 'spacer', 'display' => 'Gap']],
            ['handle' => 'name', 'field' => ['type' => 'text']],
        ]);

        $this->append($form, ['intro' => 'Intro', 'notice' => 'Notice', 'gap' => 'Gap', 'name' => 'Jack Black']);

        $this->assertEquals(['name', 'submitted_at', 'submission_id'], $this->headersWritten());
    }

    #[Test]
    public function it_writes_a_group_into_one_column_with_a_line_per_child()
    {
        $form = $this->form([
            ['handle' => 'name', 'field' => ['type' => 'text']],
            ['handle' => 'address', 'field' => ['type' => 'group', 'display' => 'Address', 'fields' => [
                ['handle' => 'street', 'field' => ['type' => 'text', 'display' => 'Street']],
                ['handle' => 'city', 'field' => ['type' => 'text', 'display' => 'City']],
                ['handle' => 'country', 'field' => ['type' => 'select', 'display' => 'Country', 'options' => ['nz' => 'New Zealand']]],
                ['handle' => 'po_box', 'field' => ['type' => 'toggle', 'display' => 'PO Box']],
                ['handle' => 'residential', 'field' => ['type' => 'yes_no', 'display' => 'Residential']],
            ]]],
        ]);

        $row = $this->append($form, [
            'name' => 'Jack Black',
            'address' => ['street' => '=1+1', 'city' => null, 'country' => 'nz', 'po_box' => false, 'residential' => 'yes'],
        ], ['fields' => ['name', 'address']]);

        $this->assertEquals(['name', 'address'], $this->headersWritten());
        $this->assertSame(['Jack Black', "Street: =1+1\nCountry: New Zealand\nPO Box: No\nResidential: Yes"], $row);
    }

    #[Test]
    public function it_writes_toggles_as_booleans()
    {
        $form = $this->form([
            ['handle' => 'subscribe', 'field' => ['type' => 'toggle']],
            ['handle' => 'agree', 'field' => ['type' => 'toggle']],
        ]);

        $row = $this->append($form, ['subscribe' => true, 'agree' => false], ['fields' => ['subscribe', 'agree']]);

        $this->assertSame([true, false], $row);
        $this->assertStringContainsString('"values":[[true,false]]', $this->requestsTo(':append')->first()->body());
    }

    #[Test]
    public function it_only_includes_the_selected_columns()
    {
        $this->append($this->form(), ['name' => 'Jack Black', 'email' => 'jack@example.com'], ['fields' => ['email', 'submitted_at']]);

        $this->assertEquals(['email', 'submitted_at'], $this->headersWritten());
    }

    #[Test]
    public function it_writes_values_into_the_columns_they_belong_in()
    {
        $row = $this->append(
            $this->form(),
            ['name' => 'Jack Black', 'email' => 'jack@example.com'],
            ['fields' => ['name', 'email']],
            headers: ['email', 'notes', 'name'],
        );

        $this->assertEquals(['jack@example.com', '', 'Jack Black'], $row);
        $this->assertNull($this->headerRequest());
    }

    #[Test]
    public function it_adds_a_column_after_the_last_header()
    {
        $row = $this->append(
            $this->form(),
            ['name' => 'Jack Black', 'email' => 'jack@example.com'],
            ['fields' => ['name', 'email']],
            headers: ['name', 'notes'],
        );

        $this->assertEquals(['email'], $this->headersWritten());
        $this->assertStringContainsString('%27Leads%202026%27%21C1%3AC1', $this->headerRequest()->url());
        $this->assertEquals(['Jack Black', '', 'jack@example.com'], $row);
    }

    #[Test]
    public function it_starts_the_row_at_the_first_header_when_the_columns_before_it_are_empty()
    {
        $row = $this->append(
            $this->form(),
            ['name' => 'Jack Black', 'email' => 'jack@example.com'],
            ['fields' => ['name', 'email']],
            headers: ['', 'name', 'notes'],
        );

        $this->assertEquals(['email'], $this->headersWritten());
        $this->assertStringContainsString('%27Leads%202026%27%21D1%3AD1', $this->headerRequest()->url());
        $this->assertEquals(['Jack Black', '', 'jack@example.com'], $row);
    }

    #[Test]
    public function it_refuses_to_append_when_a_column_sits_before_an_empty_header()
    {
        $this->fake(['name', '', 'notes', 'email']);

        $this->assertJobFails(
            $this->job($this->form(), ['name' => 'Jack Black', 'email' => 'jack@example.com'], ['fields' => ['name', 'email']]),
            'Row 1 has an empty cell before [name]',
        );
    }

    #[Test]
    public function it_widens_the_grid_before_writing_a_header_past_it()
    {
        $this->append(
            $this->form(),
            ['name' => 'Jack Black', 'email' => 'jack@example.com'],
            ['fields' => ['name', 'email']],
            headers: ['name', 'notes'],
            columns: 2,
        );

        $widen = $this->requestsTo('/v4/spreadsheets/'.self::SPREADSHEET.':batchUpdate')->first();

        $this->assertEquals([
            ['appendDimension' => ['sheetId' => 0, 'dimension' => 'COLUMNS', 'length' => 1]],
        ], $widen['requests']);

        $this->assertLessThan($this->indexOf($this->headerRequest()), $this->indexOf($widen));
    }

    #[Test]
    public function it_doesnt_widen_a_grid_thats_already_wide_enough()
    {
        $this->append(
            $this->form(),
            ['name' => 'Jack Black', 'email' => 'jack@example.com'],
            ['fields' => ['name', 'email']],
            headers: ['name'],
            columns: 2,
        );

        $this->assertEmpty($this->requestsTo(':batchUpdate'));
    }

    #[Test]
    public function it_fails_when_a_column_appears_more_than_once()
    {
        $this->fake(headers: ['name', 'notes', 'name']);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black'], ['fields' => ['name']]), 'The sheet has more than one [name] column');

        $this->assertEmpty($this->requestsTo(':append'));
    }

    #[Test]
    public function it_ignores_a_duplicate_column_it_doesnt_write_to()
    {
        $row = $this->append(
            $this->form(),
            ['name' => 'Jack Black'],
            ['fields' => ['name']],
            headers: ['name', 'notes', 'notes'],
        );

        $this->assertEquals(['Jack Black'], $row);
    }

    #[Test]
    public function it_releases_the_lock_it_took_to_add_columns()
    {
        $this->append($this->form(), ['name' => 'Jack Black'], ['fields' => ['name']]);

        $this->assertTrue(Cache::lock($this->lockKey())->get());
    }

    #[Test]
    public function it_puts_the_job_back_when_another_one_is_adding_columns()
    {
        Cache::lock($this->lockKey(), 45)->get();

        $this->fake();

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(5);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(GoogleSheetsClient::class));

        $this->assertEmpty($this->requestsTo(':append'));
        $this->assertNull($this->headerRequest());
    }

    #[Test]
    public function it_explains_that_a_spreadsheet_it_cant_open_may_not_have_been_shared()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), "make sure it's shared with ".self::SERVICE_ACCOUNT.' as an Editor');
    }

    #[Test]
    public function it_surfaces_googles_reason_when_the_tab_has_been_renamed()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*/values/*1' => Http::response([
            'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => "Unable to parse range: 'Leads 2026'!1:1"],
        ], 400)]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), "Error from Google Sheets: Unable to parse range: 'Leads 2026'!1:1");

        $this->assertNull(Cache::get($this->sheetCacheKey()));
    }

    #[Test]
    public function it_retries_with_a_fresh_title_when_the_cached_one_is_rejected()
    {
        Cache::put($this->sheetCacheKey(), ['sheetId' => 0, 'title' => 'Leads 2025']);

        $this->fake(overrides: ['sheets.googleapis.com/*/values/*1' => Http::response([
            'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => "Unable to parse range: 'Leads 2025'!1:1"],
        ], 400)]);

        $job = $this->job($this->form(), ['name' => 'Jack Black'])->withFakeQueueInteractions();
        $job->handle(app(GoogleSheetsClient::class));

        $job->assertReleased(0);
        $job->assertNotFailed();
        $this->assertNull(Cache::get($this->sheetCacheKey()));
    }

    #[Test]
    public function it_fails_when_the_tab_has_been_deleted()
    {
        $this->fake();

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black'], ['tab' => 999]), 'The tab this form writes to no longer exists');
    }

    #[Test]
    public function it_releases_the_job_when_rate_limited()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'errors' => [['reason' => 'rateLimitExceeded']], 'message' => 'Quota exceeded.'],
        ], 403)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(GoogleSheetsClient::class));
    }

    #[Test]
    public function it_releases_the_job_when_google_is_unavailable()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*' => Http::response(null, 503)]);

        $queueJob = Mockery::mock(Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        $this->job($this->form(), ['name' => 'Jack Black'])->setJob($queueJob)->handle(app(GoogleSheetsClient::class));
    }

    #[Test]
    public function it_fails_the_job_when_the_service_account_cant_write_to_the_spreadsheet()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']], 'message' => 'The caller does not have permission.'],
        ], 403)]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), 'Error from Google Sheets: The caller does not have permission.');
    }

    #[Test]
    public function it_asks_for_a_new_access_token_once_when_the_cached_one_is_rejected()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*/values/*1' => Http::sequence()
            ->push(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)
            ->push(['values' => [['name']]]),
        ]);

        $this->handle($this->form(), ['name' => 'Jack Black'], ['fields' => ['name']]);

        $this->assertCount(2, $this->requestsTo('https://oauth2.googleapis.com/token'));
        $this->assertEquals([['Jack Black']], $this->requestsTo(':append')->first()['values']);
    }

    #[Test]
    public function it_fails_the_job_when_the_access_token_is_still_rejected()
    {
        $this->fake(overrides: ['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
        ], 401)]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), 'The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is invalid.');

        $this->assertCount(2, $this->requestsTo('https://oauth2.googleapis.com/token'));
    }

    #[Test]
    public function it_fails_the_job_when_google_rejects_the_service_account_key()
    {
        $this->fake(overrides: ['oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Invalid JWT Signature.',
        ], 400)]);

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), 'Google rejected the service account key: Invalid JWT Signature.');
    }

    #[Test]
    public function it_fails_the_job_when_the_credentials_arent_a_service_account_key()
    {
        config(['statamic.forms-pro.connections.google_sheets.credentials' => base64_encode(json_encode(['type' => 'authorized_user']))]);

        Http::fake();

        $this->assertJobFails($this->job($this->form(), ['name' => 'Jack Black']), 'The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.');

        Http::assertNothingSent();
    }

    #[Test]
    public function it_doesnt_append_when_no_columns_are_left_to_write()
    {
        $this->fake();

        $this->handle($this->form(), ['name' => 'Jack Black'], ['fields' => ['deleted_field']]);

        Http::assertNothingSent();
    }

    private function form(?array $fields = null): FormContract
    {
        return tap(Form::make('contact_us')->title('Contact Us')->formFields([
            'fields' => $fields ?? [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
            ],
        ]))->save();
    }

    private function config(array $overrides = []): array
    {
        return array_merge(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0], $overrides);
    }

    private function job(FormContract $form, array $data = [], array $config = []): AppendRowToGoogleSheet
    {
        return new AppendRowToGoogleSheet($form->makeSubmission()->data($data), $this->config($config));
    }

    private function handle(FormContract $form, array $data = [], array $config = []): void
    {
        $this->job($form, $data, $config)->handle(app(GoogleSheetsClient::class));
    }

    private function append(FormContract $form, array $data = [], array $config = [], array $headers = [], int $columns = 26): array
    {
        $this->fake($headers, $columns);

        $this->handle($form, $data, $config);

        return $this->appendedRow();
    }

    private function fake(array $headers = [], int $columns = 26, array $overrides = []): void
    {
        Http::fake([
            ...$this->fakeAccessToken(),
            'sheets.googleapis.com/*:append*' => Http::response(['updates' => ['updatedRange' => "'Leads 2026'!A2:D2"]]),
            // Anchored on row 1, because the append URL contains "/values/" too.
            'sheets.googleapis.com/*/values/*1' => Http::response($headers ? ['values' => [$headers]] : []),
            'sheets.googleapis.com/*' => Http::response([
                'properties' => ['title' => 'Q3 Leads'],
                'sheets' => [
                    ['properties' => ['sheetId' => 0, 'title' => 'Leads 2026', 'gridProperties' => ['rowCount' => 1000, 'columnCount' => $columns]]],
                    ['properties' => ['sheetId' => 184, 'title' => 'Archive', 'gridProperties' => ['rowCount' => 1000, 'columnCount' => 26]]],
                ],
            ]),
            ...$overrides,
        ]);
    }

    private function requestsTo(string $url): Collection
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), $url))
            ->values();
    }

    private function appendedRow(): array
    {
        return $this->requestsTo(':append')->first()['values'][0];
    }

    private function headerRequest(): ?Request
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request) => $request->method() === 'PUT');
    }

    private function headersWritten(): array
    {
        return $this->headerRequest()['values'][0];
    }

    private function indexOf(Request $request): int
    {
        return Http::recorded()->search(fn (array $pair) => $pair[0] === $request);
    }

    private function sheetCacheKey(): string
    {
        return 'forms-pro.google-sheets.sheet.'.app(GoogleSheetsClient::class)->fingerprint().'.'.self::SPREADSHEET.'.0';
    }

    private function lockKey(): string
    {
        return 'forms-pro.google-sheets.columns.'.self::SPREADSHEET.'.0';
    }
}
