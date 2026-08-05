<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class SlackClient
{
    public function __construct(private ?string $token) {}

    public function configured(): bool
    {
        return filled($this->token);
    }

    public function postMessage(array $payload): array
    {
        try {
            return $this->call('chat.postMessage', $payload);
        } catch (SlackRequestException $e) {
            if ($e->error() === 'not_in_channel') {
                try {
                    $this->join($payload['channel']);
                } catch (SlackRequestException) {
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
            ->map(fn (array $channel) => [
                'id' => $channel['id'],
                'name' => $channel['name'],
                'is_private' => $channel['is_private'] ?? false,
            ])
            ->sortBy('name')
            ->values();
    }

    private function call(string $method, array $payload): array
    {
        throw_unless($this->configured(), SlackRequestException::missingToken());

        $response = Http::withToken($this->token)
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->post("https://slack.com/api/{$method}", $payload);

        if ($response->status() === 429) {
            throw SlackRequestException::rateLimited((int) $response->header('Retry-After') ?: 30);
        }

        $response->throw();

        $body = $response->json();

        throw_unless($body['ok'] ?? false, SlackRequestException::fromResponse($body));

        return $body;
    }
}
