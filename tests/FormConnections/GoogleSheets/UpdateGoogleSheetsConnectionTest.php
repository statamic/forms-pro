<?php

namespace Tests\FormConnections\GoogleSheets;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Tests\TestCase;

class UpdateGoogleSheetsConnectionTest extends TestCase
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
    public function it_denies_access_if_you_dont_have_permission()
    {
        $this->fake();

        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]);

        $this
            ->from('/original')
            ->actingAs($this->userWithoutPermission())
            ->patch(cp_route('forms.connect.google_sheets.update', $form->handle()), [
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 184,
            ])
            ->assertRedirect('/original')
            ->assertSessionHas('error');

        $this->assertEquals(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0], Form::find('test')->connections()->get('google_sheets'));
    }

    #[Test]
    public function it_updates_the_google_sheets_config()
    {
        $this->fake();

        $form = $this->form(connections: ['webhook' => [['id' => 'abc', 'url' => 'https://example.com/hook']]]);

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.google_sheets.update', $form->handle()), [
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 184,
                'fields' => ['email', 'submitted_at'],
            ])
            ->assertOk();

        $updated = Form::find('test');

        $this->assertEquals([
            'spreadsheet' => self::SPREADSHEET_URL,
            'tab' => 184,
            'fields' => ['email', 'submitted_at'],
        ], $updated->connections()->get('google_sheets'));

        $this->assertEquals([['id' => 'abc', 'url' => 'https://example.com/hook']], $updated->connections()->get('webhook'));
    }

    #[Test]
    public function it_stores_the_address_exactly_as_it_was_pasted()
    {
        $this->fake();

        $form = $this->form();
        $pasted = 'https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET.'/edit?gid=184#gid=184';

        $this
            ->actingAs($this->userWithPermission())
            ->patch(cp_route('forms.connect.google_sheets.update', $form->handle()), [
                'spreadsheet' => $pasted,
                'tab' => 0,
            ])
            ->assertOk();

        $this->assertEquals(['spreadsheet' => $pasted, 'tab' => 0], Form::find('test')->connections()->get('google_sheets'));
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_payloads(array $payload, array $errors)
    {
        $this->fake();

        $form = $this->form(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0]);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.google_sheets.update', $form->handle()), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertEquals(['spreadsheet' => self::SPREADSHEET_URL, 'tab' => 0], Form::find('test')->connections()->get('google_sheets'));
    }

    public static function invalidPayloads(): array
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
        $form = $this->form();

        $this->fake(['sheets.googleapis.com/*' => Http::response([
            'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
        ], 404)]);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.google_sheets.update', $form->handle()), [
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['spreadsheet' => "Couldn't open that spreadsheet. Check the address and make sure it's shared with "
                .self::SERVICE_ACCOUNT.' as an Editor.']);

        $this->assertNull(Form::find('test')->connections()->get('google_sheets'));
    }

    #[Test]
    public function it_rejects_a_form_with_a_field_named_after_a_column_it_adds()
    {
        $form = $this->form(fields: [
            ['handle' => 'email', 'field' => ['type' => 'text']],
            ['handle' => 'submitted_at', 'field' => ['type' => 'date']],
        ]);

        $this
            ->actingAs($this->userWithPermission())
            ->patchJson(cp_route('forms.connect.google_sheets.update', $form->handle()), [
                'spreadsheet' => self::SPREADSHEET_URL,
                'tab' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields' => 'This form has a field called submitted_at, which clashes with a column this connection adds. Rename the field to use this connection.']);

        $this->assertNull(Form::find('test')->connections()->get('google_sheets'));
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

    private function form(?array $config = null, array $connections = [], ?array $fields = null): FormContract
    {
        if ($config) {
            $connections['google_sheets'] = $config;
        }

        return tap(Form::make('test')->formFields([
            'fields' => $fields ?? [
                ['handle' => 'name', 'field' => ['type' => 'text']],
                ['handle' => 'email', 'field' => ['type' => 'text']],
            ],
        ])->connections($connections))->save();
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
