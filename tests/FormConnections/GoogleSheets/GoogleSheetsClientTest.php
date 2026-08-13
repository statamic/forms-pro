<?php

namespace Tests\FormConnections\GoogleSheets;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsClient;
use Statamic\FormsPro\FormConnections\GoogleSheets\GoogleSheetsException;
use Tests\TestCase;

class GoogleSheetsClientTest extends TestCase
{
    use FakesGoogleServiceAccount;

    #[Test]
    public function it_reads_the_service_account_address_from_the_credentials()
    {
        $this->assertEquals(self::SERVICE_ACCOUNT, $this->client($this->credentials())->serviceAccountEmail());
    }

    #[Test]
    public function it_fingerprints_the_credentials()
    {
        $fingerprint = $this->client($this->credentials())->fingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $fingerprint);
        $this->assertNotEquals($fingerprint, $this->client($this->credentials(['private_key_id' => 'rotated']))->fingerprint());
    }

    #[Test]
    public function it_requires_credentials()
    {
        $this->expectExceptionMessage('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is required to use the Google Sheets connection.');

        $this->client(null)->serviceAccountEmail();
    }

    #[Test]
    #[DataProvider('invalidCredentialsProvider')]
    public function it_rejects_credentials_that_arent_a_service_account_key(string $credentials)
    {
        $this->expectExceptionMessage('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.');

        $this->client($credentials)->serviceAccountEmail();
    }

    public static function invalidCredentialsProvider(): array
    {
        $account = [
            'type' => 'service_account',
            'private_key_id' => 'e3b0c442',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
            'client_email' => 'forms-pro@forms-pro-testing.iam.gserviceaccount.com',
        ];

        return [
            'not base64' => ['nope!'],
            'not json' => [base64_encode('nope')],
            'not an object' => [base64_encode('["nope"]')],
            'an oauth client' => [base64_encode(json_encode(['type' => 'authorized_user'] + $account))],
            'without an address' => [base64_encode(json_encode(array_diff_key($account, ['client_email' => null])))],
            'without a key' => [base64_encode(json_encode(array_diff_key($account, ['private_key' => null])))],
            'without a key id' => [base64_encode(json_encode(array_diff_key($account, ['private_key_id' => null])))],
        ];
    }

    #[Test]
    public function it_doesnt_put_the_private_key_in_the_error_when_it_cant_be_signed_with()
    {
        Http::fake();

        try {
            $this->client($this->credentials(['private_key' => 'not-a-pem-encoded-key']))->spreadsheet('abc');
            $this->fail('No exception was thrown.');
        } catch (GoogleSheetsException $e) {
            $this->assertEquals('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.', $e->getMessage());
            $this->assertStringNotContainsString('not-a-pem-encoded-key', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function it_signs_a_json_web_token_to_get_an_access_token()
    {
        Http::fake([...$this->fakeAccessToken(), 'sheets.googleapis.com/*' => Http::response()]);

        $this->client($this->credentials())->spreadsheet('abc');

        $assertion = $this->tokenRequests()->first();

        $this->assertEquals('urn:ietf:params:oauth:grant-type:jwt-bearer', $assertion['grant_type']);

        [$header, $claims, $signature] = explode('.', $assertion['assertion']);

        $this->assertEquals(['alg' => 'RS256', 'typ' => 'JWT'], $this->decode($header));
        $this->assertEquals([
            'iss' => self::SERVICE_ACCOUNT,
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => 'https://oauth2.googleapis.com/token',
        ], collect($this->decode($claims))->except(['iat', 'exp'])->all());

        $this->assertEquals(3600, $this->decode($claims)['exp'] - $this->decode($claims)['iat']);
        $this->assertLessThan(time(), $this->decode($claims)['iat']);

        $this->assertEquals(1, openssl_verify(
            "{$header}.{$claims}",
            base64_decode(strtr($signature, '-_', '+/')),
            file_get_contents(__DIR__.'/../../__fixtures__/google-sheets/public-key.pem'),
            OPENSSL_ALGO_SHA256
        ));
    }

    #[Test]
    public function it_caches_the_access_token()
    {
        Http::fake([...$this->fakeAccessToken(), 'sheets.googleapis.com/*' => Http::response()]);

        $client = $this->client($this->credentials());

        $client->spreadsheet('abc');
        $client->spreadsheet('abc');

        $this->assertCount(1, $this->tokenRequests());
    }

    #[Test]
    public function it_retries_when_google_cant_issue_an_access_token()
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(null, 503)]);

        try {
            $this->client($this->credentials())->spreadsheet('abc');
            $this->fail('No exception was thrown.');
        } catch (GoogleSheetsException $e) {
            $this->assertEquals("Google couldn't issue an access token, responding with a [503] status code.", $e->getMessage());
            $this->assertTrue($e->isRetryable());
            $this->assertEquals(30, $e->retryAfter());
        }
    }

    #[Test]
    public function it_throws_when_google_returns_no_access_token()
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['expires_in' => 3599])]);

        $this->expectExceptionMessage("Google didn't return an access token.");

        $this->client($this->credentials())->spreadsheet('abc');
    }

    private function client(?string $credentials): GoogleSheetsClient
    {
        return new GoogleSheetsClient($credentials);
    }

    private function tokenRequests(): Collection
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token')
            ->values();
    }

    private function decode(string $segment): array
    {
        return json_decode(base64_decode(strtr($segment, '-_', '+/')), true);
    }
}
