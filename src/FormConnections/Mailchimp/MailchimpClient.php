<?php

namespace Statamic\FormsPro\FormConnections\Mailchimp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Support\Str;

class MailchimpClient
{
    private const PER_PAGE = 1000;

    private const RETRY_AFTER = 30;

    private const COMPLIANCE_TITLES = ['Member In Compliance State', 'Forgotten Email Not Subscribed'];

    public function __construct(private ?string $apiKey) {}

    public function audiences(): array
    {
        return collect($this->call('GET', 'lists', ['count' => self::PER_PAGE])['lists'] ?? [])
            ->sortBy('name')
            ->mapWithKeys(fn (array $audience): array => [$audience['id'] => $audience['name']])
            ->all();
    }

    public function mergeFields(string $audience): array
    {
        return collect($this->call('GET', "lists/{$audience}/merge-fields", ['count' => self::PER_PAGE])['merge_fields'] ?? [])
            ->sortBy('name')
            ->map(fn (array $mergeField): array => ['tag' => $mergeField['tag'], 'name' => $mergeField['name']])
            ->values()
            ->all();
    }

    public function subscribe(string $audience, array $member): array
    {
        return $this->call('PUT', "lists/{$audience}/members/".$this->subscriberHash($member['email_address']), $member);
    }

    public function tag(string $audience, string $email, array $tags): void
    {
        $this->call('POST', "lists/{$audience}/members/".$this->subscriberHash($email).'/tags', [
            'tags' => collect($tags)->map(fn (string $tag): array => ['name' => $tag, 'status' => 'active'])->all(),
        ]);
    }

    private function subscriberHash(string $email): string
    {
        return md5(Str::lower($email));
    }

    private function call(string $method, string $uri, array $data): array
    {
        return $this->handle(
            $this->request()->send(
                $method,
                $uri,
                $method === 'GET' ? ['query' => $data] : ['json' => $data]
            )
        );
    }

    private function request(): PendingRequest
    {
        throw_unless($this->apiKey, new MailchimpException(
            'The [FORMS_PRO_MAILCHIMP_API_KEY] environment variable is required to use the Mailchimp connection.'
        ));

        return Http::withToken($this->apiKey)
            ->baseUrl('https://'.Str::afterLast($this->apiKey, '-').'.api.mailchimp.com/3.0/')
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
            $retryAfter = (int) $response->header('Retry-After') ?: self::RETRY_AFTER;

            throw new MailchimpException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", retryAfter: $retryAfter);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new MailchimpException('The [FORMS_PRO_MAILCHIMP_API_KEY] environment variable is invalid.');
        }

        if ($response->serverError()) {
            throw new MailchimpException(
                "Mailchimp responded with a [{$response->status()}] status code.",
                retryAfter: self::RETRY_AFTER,
            );
        }

        throw new MailchimpException(
            $this->errorMessage($response),
            complianceState: in_array($response->json('title'), self::COMPLIANCE_TITLES),
        );
    }

    private function errorMessage(Response $response): string
    {
        $detail = $response->json('detail') ?: "Mailchimp responded with a [{$response->status()}] status code.";

        $errors = collect($response->json('errors', []))
            ->map(fn (array $error) => $error['message'] ?? null)
            ->filter()
            ->implode(' ');

        return trim("{$detail} {$errors}");
    }
}
