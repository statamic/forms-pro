<?php

namespace Tests\FormConnectors\GoogleSheets;

use Illuminate\Support\Facades\Http;

trait FakesGoogleServiceAccount
{
    private const string SERVICE_ACCOUNT = 'forms-pro@forms-pro-testing.iam.gserviceaccount.com';

    private const string SPREADSHEET = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdef';

    private const string SPREADSHEET_URL = 'https://docs.google.com/spreadsheets/d/'.self::SPREADSHEET.'/edit';

    private function credentials(array $overrides = []): string
    {
        return base64_encode(json_encode([
            'type' => 'service_account',
            'project_id' => 'forms-pro-testing',
            'private_key_id' => 'e3b0c44298fc1c149afbf4c8996fb924',
            'private_key' => file_get_contents(__DIR__.'/../../__fixtures__/google-sheets/private-key.pem'),
            'client_email' => self::SERVICE_ACCOUNT,
            ...$overrides,
        ]));
    }

    private function fakeAccessToken(): array
    {
        return ['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3599])];
    }
}
