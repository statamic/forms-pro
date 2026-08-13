<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SlackClient
{
    private const int RETRY_AFTER = 30;

    private const array RETRYABLE = ['internal_error', 'fatal_error'];

    public function __construct(private readonly ?string $token) {}

    public function fingerprint(): string
    {
        return substr(hash('xxh128', (string) $this->token), 0, 8);
    }

    public function postMessage(array $payload): array
    {
        try {
            return $this->call('chat.postMessage', $payload);
        } catch (SlackException $e) {
            if ($e->error() === 'not_in_channel') {
                try {
                    $this->join($payload['channel']);
                } catch (SlackException) {
                    throw $e;
                }

                return $this->call('chat.postMessage', $payload);
            }

            throw $e;
        }
    }

    private function join(string $channel): void
    {
        $this->call('conversations.join', ['channel' => $channel]);
    }

    public function channels(): Collection
    {
        $cursor = '';
        $channels = collect();

        do {
            $response = $this->call('conversations.list', [
                'exclude_archived' => true,
                'types' => 'public_channel,private_channel',
                'limit' => 200,
                'cursor' => $cursor,
            ]);

            $channels = $channels->merge($response['channels']);
            $cursor = $response['response_metadata']['next_cursor'] ?? '';
        } while (filled($cursor));

        return $channels
            ->map(fn (array $channel): array => [
                'id' => $channel['id'],
                'name' => $channel['name'],
                'is_private' => $channel['is_private'] ?? false,
            ])
            ->sortBy('name')
            ->values();
    }

    private function call(string $method, array $payload): array
    {
        throw_unless($this->token, new SlackException(
            'The [FORMS_PRO_SLACK_TOKEN] environment variable is required to use the Slack connection.'
        ));

        $response = Http::withToken($this->token)
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->post("https://slack.com/api/{$method}", $payload);

        if ($response->status() === 429) {
            $retryAfter = (int) $response->header('Retry-After') ?: self::RETRY_AFTER;

            throw new SlackException(
                message: "Rate limit exceeded. Please try again in {$retryAfter} seconds.",
                error: 'ratelimited',
                retryAfter: $retryAfter,
            );
        }

        if ($response->failed()) {
            throw new SlackException(
                message: "Slack responded with a [{$response->status()}] status code.",
                retryAfter: $response->serverError() ? self::RETRY_AFTER : null,
            );
        }

        $body = $response->json();

        if (! ($body['ok'] ?? false)) {
            $error = $body['error'] ?? 'unknown_error';

            throw new SlackException(
                message: "Error from Slack: [{$error}].",
                error: $error,
                retryAfter: in_array($error, self::RETRYABLE) ? self::RETRY_AFTER : null,
            );
        }

        return $body;
    }
}
