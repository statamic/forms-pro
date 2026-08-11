<?php

namespace Statamic\FormsPro\FormConnections\HubSpot;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Support\Arr;

class HubSpotClient
{
    private const string API_URL = 'https://api.hubapi.com/';

    private const string FORMS_URL = 'https://api.hsforms.com/';

    private const int PER_PAGE = 100;

    private const int RATE_LIMIT_RETRY_AFTER = 10;

    private const int DAILY_RATE_LIMIT_RETRY_AFTER = 3600;

    private const int SERVER_ERROR_RETRY_AFTER = 30;

    private const array SKIPPABLE_ERRORS = ['INVALID_EMAIL', 'BLOCKED_EMAIL', 'INVALID_NUMBER', 'NUMBER_OUT_OF_RANGE'];

    private ?int $portalId = null;

    public function __construct(private readonly ?string $accessToken) {}

    public function portalId(): int
    {
        return $this->portalId ??= Cache::remember(
            "forms-pro.hubspot.portal.{$this->fingerprint()}",
            now()->addDay(),
            fn (): int => $this->handle($this->request()->get('account-info/v3/details'))['portalId']
        );
    }

    public function fingerprint(): string
    {
        return substr(hash('xxh128', (string) $this->accessToken), 0, 8);
    }

    public function forms(): array
    {
        return $this->paginate('marketing/v3/forms', ['formTypes' => 'hubspot', 'archived' => 'false'])
            ->sortBy('name')
            ->mapWithKeys(fn (array $form): array => [$form['id'] => $this->summarise($form)])
            ->all();
    }

    public function form(string $form): array
    {
        return Cache::remember(
            "forms-pro.hubspot.form.{$this->fingerprint()}.{$form}",
            now()->addMinutes(5),
            fn (): array => $this->handle($this->request()->get("marketing/v3/forms/{$form}"))
        );
    }

    public function subscriptions(): array
    {
        $definitions = $this->handle($this->request()->get('communication-preferences/v3/definitions'));

        return collect($definitions['subscriptionDefinitions'] ?? [])
            ->mapWithKeys(fn (array $subscription): array => [(int) $subscription['id'] => $subscription['name']])
            ->all();
    }

    public function submit(string $form, array $payload): array
    {
        return $this->handle($this->request(self::FORMS_URL)->post(
            "submissions/v3/integration/secure/submit/{$this->portalId()}/{$form}",
            $payload
        ));
    }

    private function summarise(array $form): array
    {
        $fields = collect($form['fieldGroups'] ?? [])->flatMap(fn (array $group): array => $group['fields'] ?? []);

        return [
            'name' => $form['name'],
            'properties' => $fields
                ->reject(fn (array $field): bool => $field['name'] === 'email')
                ->map(fn (array $field): array => Arr::removeNullValues([
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'object_type_id' => $field['objectTypeId'],
                    'required' => (bool) ($field['required'] ?? false),
                ]))
                ->values()
                ->all(),
            'consent' => $this->consent($form['legalConsentOptions'] ?? []),
            'captcha' => (bool) Arr::get($form, 'configuration.recaptchaEnabled', false),
            'has_email' => $fields->contains(fn (array $field): bool => $field['name'] === 'email'),
        ];
    }

    private function consent(array $options): array
    {
        return Arr::removeNullValues([
            'type' => $options['type'] ?? 'none',
            'checkboxes' => collect($options['communicationsCheckboxes'] ?? [])
                ->map(fn (array $checkbox): array => [
                    'id' => (int) $checkbox['subscriptionTypeId'],
                    'label' => $checkbox['label'],
                    'required' => (bool) $checkbox['required'],
                ])
                ->all() ?: null,
            'subscription_ids' => array_map('intval', $options['subscriptionTypeIds'] ?? []) ?: null,
        ]);
    }

    private function paginate(string $uri, array $query = []): Collection
    {
        $results = collect();
        $after = null;

        do {
            $response = $this->handle($this->request()->get($uri, array_filter([
                ...$query,
                'limit' => self::PER_PAGE,
                'after' => $after,
            ])));

            $results = $results->merge($response['results'] ?? []);
            $after = $response['paging']['next']['after'] ?? null;
        } while ($after);

        return $results;
    }

    private function request(string $baseUrl = self::API_URL): PendingRequest
    {
        throw_unless($this->accessToken, new HubSpotException(
            'The [FORMS_PRO_HUBSPOT_ACCESS_TOKEN] environment variable is required to use the HubSpot connection.'
        ));

        return Http::withToken($this->accessToken)
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    private function handle(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After')
                ?: ($response->json('policyName') === 'DAILY' ? self::DAILY_RATE_LIMIT_RETRY_AFTER : self::RATE_LIMIT_RETRY_AFTER);

            throw new HubSpotException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->status() === 477) {
            $retryAfter = (int) $response->header('Retry-After') ?: self::SERVER_ERROR_RETRY_AFTER;

            throw new HubSpotException("HubSpot is migrating this account. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->unauthorized()) {
            throw new HubSpotException('The [FORMS_PRO_HUBSPOT_ACCESS_TOKEN] environment variable is invalid.');
        }

        if ($response->forbidden()) {
            throw new HubSpotException($this->scopeErrorMessage($response));
        }

        if ($response->notFound()) {
            throw new HubSpotException('The selected HubSpot form no longer exists.');
        }

        if ($response->serverError()) {
            throw new HubSpotException(
                "HubSpot responded with a [{$response->status()}] status code.",
                self::SERVER_ERROR_RETRY_AFTER,
            );
        }

        $skippable = collect($response->json('errors', []))
            ->pluck('errorType')
            ->intersect(self::SKIPPABLE_ERRORS)
            ->isNotEmpty();

        throw new HubSpotException($this->errorMessage($response), skippable: $skippable);
    }

    private function scopeErrorMessage(Response $response): string
    {
        if ($response->json('category') !== 'MISSING_SCOPE') {
            return 'Your HubSpot account doesn\'t have permission to do this.';
        }

        $scopes = collect($response->json('errors', []))
            ->flatMap(fn (array $error): array => $error['context']['requiredGranularScopes'] ?? [])
            ->unique()
            ->implode(', ');

        return "The HubSpot access token is missing a required scope. It needs one of: {$scopes}.";
    }

    private function errorMessage(Response $response): string
    {
        $errors = collect($response->json('errors', []))->pluck('message')->filter()->implode(' ');

        return $errors
            ? "Error from HubSpot: {$errors}"
            : "HubSpot responded with a [{$response->status()}] status code.";
    }
}
