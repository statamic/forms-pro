<?php

namespace Statamic\FormsPro\FormConnectors\Kit;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class KitClient
{
    private const int PER_PAGE = 1000;

    private const int RATE_LIMIT_RETRY_AFTER = 60;

    private const int SERVER_ERROR_RETRY_AFTER = 30;

    public function __construct(private readonly ?string $apiKey) {}

    public function fingerprint(): string
    {
        return substr(hash('xxh128', (string) $this->apiKey), 0, 8);
    }

    public function forms(): array
    {
        return $this->paginate('forms', ['type' => 'embed', 'status' => 'active'])
            ->sortBy('name')
            ->mapWithKeys(fn (array $form): array => [$form['id'] => $form['name']])
            ->all();
    }

    public function tags(): array
    {
        return $this->paginate('tags')
            ->sortBy('name')
            ->mapWithKeys(fn (array $tag): array => [$tag['id'] => $tag['name']])
            ->all();
    }

    public function customFields(): array
    {
        return $this->paginate('custom_fields')
            ->sortBy('label')
            ->mapWithKeys(fn (array $field): array => [$field['key'] => $field['label']])
            ->all();
    }

    public function subscribe(array $subscriber): array
    {
        return $this->handle($this->request()->post('subscribers', $subscriber));
    }

    public function addToForm(int $form, string $email): void
    {
        $this->handle($this->request()->post("forms/{$form}/subscribers", ['email_address' => $email]));
    }

    public function tag(int $tag, string $email): void
    {
        $this->handle($this->request()->post("tags/{$tag}/subscribers", ['email_address' => $email]));
    }

    private function paginate(string $uri, array $query = []): Collection
    {
        $results = collect();
        $after = null;

        do {
            $response = $this->handle($this->request()->get($uri, array_filter([
                ...$query,
                'per_page' => self::PER_PAGE,
                'after' => $after,
            ])));

            $results = $results->merge($response[$uri] ?? []);
            $after = $response['pagination']['end_cursor'] ?? null;
        } while ($response['pagination']['has_next_page'] ?? false);

        return $results;
    }

    private function request(): PendingRequest
    {
        throw_unless($this->apiKey, new KitException(
            'The [FORMS_PRO_KIT_API_KEY] environment variable is required to use the Kit connection.'
        ));

        return Http::withHeaders(['X-Kit-Api-Key' => $this->apiKey])
            ->baseUrl('https://api.kit.com/v4/')
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
            $retryAfter = (int) $response->header('Retry-After') ?: self::RATE_LIMIT_RETRY_AFTER;

            throw new KitException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new KitException('The [FORMS_PRO_KIT_API_KEY] environment variable is invalid. Keys from the "V3 Key" section in Kit won\'t work.');
        }

        if ($response->notFound()) {
            throw new KitException('The selected Kit form or tag no longer exists.');
        }

        if ($response->status() === 422) {
            throw new KitException('Error from Kit: '.collect($response->json('errors', []))->implode(' '));
        }

        throw new KitException(
            "Kit responded with a [{$response->status()}] status code.",
            $response->serverError() ? self::SERVER_ERROR_RETRY_AFTER : null,
        );
    }
}
