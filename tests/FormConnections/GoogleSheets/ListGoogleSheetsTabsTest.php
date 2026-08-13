<?php

namespace Tests\FormConnections\GoogleSheets;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class ListGoogleSheetsTabsTest extends TestCase
{
    use FakesGoogleServiceAccount;
    use FakesRoles;
    use PreventsSavingStacheItemsToDisk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['forms-pro.connections.google_sheets.credentials' => $this->credentials()]);
    }

    #[Test]
    public function it_lists_the_tabs_in_a_spreadsheet()
    {
        $this->fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url(self::SPREADSHEET))
            ->assertOk()
            ->assertExactJson([
                'tabs' => [0 => 'Leads 2026', 184 => 'Archive'],
                'error' => null,
            ]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/'
            .self::SPREADSHEET.'?fields=properties.title%2Csheets.properties');
    }

    #[Test]
    #[DataProvider('spreadsheetProvider')]
    public function it_accepts_an_address_or_a_bare_id(string $spreadsheet)
    {
        $this->fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url($spreadsheet))
            ->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/spreadsheets/'.self::SPREADSHEET.'?'));
    }

    public static function spreadsheetProvider(): array
    {
        return [
            'bare id' => [self::SPREADSHEET],
            'address' => ['https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET.'/edit?gid=0#gid=0'],
            'address without a path' => ['https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET],
            'padded' => ['  '.self::SPREADSHEET.'  '],
        ];
    }

    #[Test]
    #[DataProvider('invalidSpreadsheetProvider')]
    public function it_rejects_an_address_it_cant_read_an_id_from(?string $spreadsheet, string $error)
    {
        $this->fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url($spreadsheet))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['spreadsheet' => $error]);

        Http::assertNothingSent();
    }

    public static function invalidSpreadsheetProvider(): array
    {
        $error = 'Paste the address of a Google Sheet.';

        return [
            'missing' => [null, 'This field is required.'],
            'not an address' => ['nope', $error],
            'a different google document' => ['https://docs.google.com/document/d/'.self::SPREADSHEET.'/edit', $error],
            'a published spreadsheet' => ['https://docs.google.com/spreadsheets/d/e/2PACX-1vQx8/pubhtml', $error],
        ];
    }

    #[Test]
    public function it_explains_that_a_spreadsheet_it_cant_open_may_not_have_been_shared()
    {
        $this->fake(['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url(self::SPREADSHEET))
            ->assertOk()
            ->assertJson([
                'tabs' => [],
                'error' => "Couldn't open that spreadsheet. Check the address and make sure it's shared with "
                    .self::SERVICE_ACCOUNT.' as an Editor.',
            ]);
    }

    #[Test]
    public function it_reports_invalid_credentials()
    {
        config(['forms-pro.connections.google_sheets.credentials' => 'not-base64-encoded-json']);

        Http::fake();

        $this
            ->actingAs($this->userWithPermission())
            ->getJson($this->url(self::SPREADSHEET))
            ->assertOk()
            ->assertJson(['error' => 'The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.']);

        Http::assertNothingSent();
    }

    #[Test]
    public function it_denies_access_if_you_dont_have_permission()
    {
        $this->fake();

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->get($this->url(self::SPREADSHEET))
            ->assertRedirect('/original');

        Http::assertNothingSent();
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

    private function url(?string $spreadsheet): string
    {
        return cp_route('forms.connect.google_sheets.tabs', $this->form()->handle())
            .'?'.http_build_query(['spreadsheet' => $spreadsheet]);
    }

    private function form(): FormContract
    {
        return tap(Form::make('test')->formFields([
            'fields' => [['handle' => 'email', 'field' => ['type' => 'email']]],
        ]))->save();
    }

    private function userWithoutPermission()
    {
        $this->setTestRoles(['test' => ['access cp']]);

        return tap(User::make()->assignRole('test'))->save();
    }

    private function userWithPermission()
    {
        $this->setTestRoles(['test' => ['access cp', 'edit forms']]);

        return tap(User::make()->assignRole('test'))->save();
    }
}
