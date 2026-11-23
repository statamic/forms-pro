<?php

namespace Statamic\FormsPro\FormConnectors\Mailcoach;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Support\Str;

class MailcoachClient
{
    private const int PER_PAGE = 100;

    private const int RETRY_AFTER = 30;

    public function __construct(private readonly ?string $apiUrl, private readonly ?string $token) {}

    public function fingerprint(): string
    {
        return substr(hash('xxh128', $this->apiUrl.$this->token), 0, 8);
    }

    public function lists(): array
    {
        $lists = collect();
        $page = 1;

        do {
            $response = $this->handle($this->request()->get('email-lists', [
                'page' => $page++,
                'per_page' => self::PER_PAGE,
            ]));

            $lists = $lists->merge($response['data'] ?? []);
        } while (filled($response['links']['next'] ?? null));

        return $lists
            ->sortBy('name')
            ->mapWithKeys(fn (array $list): array => [$list['uuid'] => $list['name']])
            ->all();
    }

    public function subscribe(string $list, array $subscriber): array
    {
        return $this->handle($this->request()->post("email-lists/{$list}/subscribers", $subscriber))['data'] ?? [];
    }

    public function isUnsubscribed(string $list, string $email): bool
    {
        $response = $this->handle($this->request()->get("email-lists/{$list}/subscribers", [
            'filter' => ['email' => $email, 'status' => 'unsubscribed'],
        ]));

        return collect($response['data'] ?? [])
            ->contains(fn (array $subscriber): bool => Str::lower($subscriber['email'] ?? '') === Str::lower($email));
    }

    public function addTags(string $subscriber, array $tags): void
    {
        $this->handle($this->request()->post("subscribers/{$subscriber}/tags", ['tags' => $tags]));
    }

    private function request(): PendingRequest
    {
        throw_unless(
            $this->apiUrl && $this->token,
            new MailcoachException('The [FORMS_PRO_MAILCOACH_API_URL] and [FORMS_PRO_MAILCOACH_TOKEN] environment variables are required to use the Mailcoach connection.')
        );

        $apiUrl = rtrim($this->apiUrl, '/');

        return Http::withToken($this->token)
            ->baseUrl(Str::endsWith($apiUrl, '/api') ? $apiUrl : $apiUrl.'/api')
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

            throw new MailcoachException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new MailcoachException('The [FORMS_PRO_MAILCOACH_TOKEN] environment variable is invalid.');
        }

        if ($response->status() === 422) {
            throw new MailcoachException('Error from Mailcoach: '.collect($response->json('errors', []))->flatten()->implode(' '));
        }

        throw new MailcoachException(
            "Mailcoach responded with a [{$response->status()}] status code.",
            $response->serverError() ? self::RETRY_AFTER : null,
        );
    }
}
