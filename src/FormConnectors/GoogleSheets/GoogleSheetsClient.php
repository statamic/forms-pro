<?php

namespace Statamic\FormsPro\FormConnectors\GoogleSheets;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Support\Arr;

use function Statamic\trans as __;

class GoogleSheetsClient
{
    private const string API_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';

    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const string SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private const string GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const int TOKEN_LIFETIME = 3600;

    private const int CLOCK_SKEW = 30;

    private const int RETRY_AFTER = 30;

    private const array RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded'];

    private ?array $serviceAccount = null;

    public function __construct(private ?string $credentials) {}

    public function serviceAccountEmail(): string
    {
        return $this->serviceAccount()['client_email'];
    }

    public function fingerprint(): string
    {
        $account = $this->serviceAccount();

        return substr(hash('xxh128', $account['client_email'].$account['private_key_id']), 0, 16);
    }

    public function spreadsheet(string $id): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get($id, [
            'fields' => 'properties.title,sheets.properties',
        ]));
    }

    public function tabs(string $id): array
    {
        return collect(Arr::get($this->spreadsheet($id), 'sheets', []))
            ->pluck('properties')
            ->mapWithKeys(fn (array $properties): array => [$properties['sheetId'] => $properties['title']])
            ->all();
    }

    public function headerRow(string $id, string $title): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get($this->values($id, $title)));

        return Arr::get($response, 'values.0', []);
    }

    public function widenGrid(string $id, int $sheetId, int $columns): void
    {
        $this->send(fn (PendingRequest $request) => $request->post("{$id}:batchUpdate", [
            'requests' => [
                ['appendDimension' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'length' => $columns]],
            ],
        ]));
    }

    public function addHeaders(string $id, string $title, int $column, array $headers): void
    {
        $cells = $this->column($column).'1:'.$this->column($column + count($headers) - 1).'1';

        $this->send(fn (PendingRequest $request) => $request
            ->withQueryParameters(['valueInputOption' => 'RAW'])
            ->put($this->values($id, $title, $cells), ['values' => [$headers]]));
    }

    private function column(int $index): string
    {
        $column = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $column = chr(65 + ($index - 1) % 26).$column;
        }

        return $column;
    }

    public function append(string $id, string $title, array $row): array
    {
        return $this->send(fn (PendingRequest $request) => $request
            ->withQueryParameters(['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'])
            ->post($this->values($id, $title).':append', ['values' => [$row]]));
    }

    /**
     * The whole header row rather than a single cell, so that Google looks at
     * every header when working out which table it's appending to.
     */
    private function values(string $id, string $title, string $cells = '1:1'): string
    {
        $range = "'".str_replace("'", "''", $title)."'!".$cells;

        return "{$id}/values/".rawurlencode($range);
    }

    private function send(Closure $request): array
    {
        $response = $request($this->request());

        if ($response->unauthorized()) {
            Cache::forget($this->tokenCacheKey());

            $response = $request($this->request());
        }

        return $this->handle($response);
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->baseUrl(self::API_URL)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    private function handle(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $reason = $response->json('error.errors.0.reason');

        if ($response->status() === 429 || in_array($reason, self::RATE_LIMIT_REASONS, true)) {
            $retryAfter = (int) $response->header('Retry-After') ?: self::RETRY_AFTER;

            throw new GoogleSheetsException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->serverError()) {
            throw new GoogleSheetsException(
                "Google Sheets responded with a [{$response->status()}] status code.",
                self::RETRY_AFTER,
            );
        }

        if ($response->unauthorized()) {
            throw new GoogleSheetsException('The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is invalid.');
        }

        if ($response->notFound()) {
            throw new GoogleSheetsException(__('forms-pro::messages.google_sheets_not_found', [
                'email' => $this->serviceAccountEmail(),
            ]));
        }

        $message = $response->json('error.message')
            ? "Error from Google Sheets: {$response->json('error.message')}"
            : "Google Sheets responded with a [{$response->status()}] status code.";

        throw new GoogleSheetsException($message);
    }

    private function accessToken(): string
    {
        if ($token = Cache::get($key = $this->tokenCacheKey())) {
            return $token;
        }

        $response = Http::asForm()->connectTimeout(5)->timeout(30)->post(self::TOKEN_URL, [
            'grant_type' => self::GRANT_TYPE,
            'assertion' => $this->assertion(),
        ]);

        $token = $this->token($response);

        Cache::put($key, $token, now()->addSeconds((int) $response->json('expires_in') - 60));

        return $token;
    }

    /**
     * The token endpoint isn't a Sheets endpoint and doesn't share its error
     * shape, so it gets its own handling.
     */
    private function token(Response $response): string
    {
        if ($response->status() === 429 || $response->serverError()) {
            throw new GoogleSheetsException(
                "Google couldn't issue an access token, responding with a [{$response->status()}] status code.",
                (int) $response->header('Retry-After') ?: self::RETRY_AFTER,
            );
        }

        if ($response->failed()) {
            throw new GoogleSheetsException(
                'Google rejected the service account key: '.($response->json('error_description') ?? $response->json('error') ?? 'no reason given.')
            );
        }

        throw_unless(
            filled($token = $response->json('access_token')),
            new GoogleSheetsException("Google didn't return an access token.")
        );

        return $token;
    }

    private function tokenCacheKey(): string
    {
        return "forms-pro.google-sheets.token.{$this->fingerprint()}";
    }

    private function assertion(): string
    {
        $account = $this->serviceAccount();
        $issuedAt = time() - self::CLOCK_SKEW;

        $payload = $this->base64(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$this->base64([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TOKEN_LIFETIME,
        ]);

        $key = openssl_pkey_get_private($account['private_key']);

        throw_unless($key, new GoogleSheetsException($this->invalidKeyMessage()));
        throw_unless(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256), new GoogleSheetsException($this->invalidKeyMessage()));

        return $payload.'.'.$this->base64($signature);
    }

    private function base64(array|string $value): string
    {
        $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;

        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function serviceAccount(): array
    {
        if ($this->serviceAccount) {
            return $this->serviceAccount;
        }

        throw_unless($this->credentials, new GoogleSheetsException(
            'The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is required to use the Google Sheets connection.'
        ));

        $decoded = base64_decode($this->credentials, true);
        $account = $decoded ? json_decode($decoded, true) : null;

        throw_unless(
            is_array($account)
                && Arr::get($account, 'type') === 'service_account'
                && filled(Arr::get($account, 'client_email'))
                && filled(Arr::get($account, 'private_key'))
                && filled(Arr::get($account, 'private_key_id')),
            new GoogleSheetsException($this->invalidKeyMessage())
        );

        return $this->serviceAccount = $account;
    }

    private function invalidKeyMessage(): string
    {
        return 'The [FORMS_PRO_GOOGLE_SHEETS_CREDENTIALS] environment variable is not a valid service account key.';
    }
}
