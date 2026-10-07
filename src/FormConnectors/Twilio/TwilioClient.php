<?php

namespace Statamic\FormsPro\FormConnectors\Twilio;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Statamic\Support\Arr;

class TwilioClient
{
    private const string API_URL = 'https://api.twilio.com/2010-04-01/';

    private const string MESSAGING_URL = 'https://messaging.twilio.com/v1/';

    private const int PER_PAGE = 1000;

    private const int RETRY_AFTER = 30;

    private const array SKIPPABLE_ERRORS = [21211, 21214, 21610, 21614, 21617];

    public function __construct(
        private ?string $accountSid,
        private ?string $apiKey,
        private ?string $apiSecret,
    ) {}

    public function fingerprint(): string
    {
        return substr(hash('xxh128', $this->accountSid.$this->apiKey), 0, 8);
    }

    public function sendMessage(array $payload): array
    {
        return $this->handle(
            $this->request()->asForm()->post("Accounts/{$this->accountSid}/Messages.json", $payload)
        );
    }

    public function phoneNumbers(): array
    {
        $response = $this->handle($this->request()->get(
            "Accounts/{$this->accountSid}/IncomingPhoneNumbers.json",
            ['PageSize' => self::PER_PAGE]
        ));

        return collect($response['incoming_phone_numbers'] ?? [])
            ->filter(fn (array $number): bool => (bool) Arr::get($number, 'capabilities.sms'))
            ->sortBy('phone_number')
            ->mapWithKeys(fn (array $number): array => [$number['phone_number'] => $this->numberLabel($number)])
            ->all();
    }

    /**
     * The "friendly name" defaults to the number itself. We only want to show
     * it when it's been customized.
     */
    private function numberLabel(array $number): string
    {
        $friendlyName = $number['friendly_name'] ?? '';

        return preg_match('/\p{L}/u', $friendlyName)
            ? "{$number['phone_number']} ({$friendlyName})"
            : $number['phone_number'];
    }

    public function messagingServices(): array
    {
        $response = $this->handle(
            $this->request(self::MESSAGING_URL)->get('Services', ['PageSize' => self::PER_PAGE])
        );

        return collect($response['services'] ?? [])
            ->sortBy('friendly_name')
            ->mapWithKeys(fn (array $service): array => [$service['sid'] => $service['friendly_name']])
            ->all();
    }

    private function request(string $baseUrl = self::API_URL): PendingRequest
    {
        throw_unless($this->accountSid && $this->apiKey && $this->apiSecret, new TwilioException(
            'The [FORMS_PRO_TWILIO_ACCOUNT_SID], [FORMS_PRO_TWILIO_API_KEY] and [FORMS_PRO_TWILIO_API_SECRET] environment variables are required to use the Twilio connection.'
        ));

        return Http::withBasicAuth($this->apiKey, $this->apiSecret)
            ->baseUrl($baseUrl)
            ->acceptJson()
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

            throw new TwilioException("Rate limit exceeded. Please try again in {$retryAfter} seconds.", $retryAfter);
        }

        if ($response->serverError()) {
            throw new TwilioException(
                "Twilio responded with a [{$response->status()}] status code.",
                self::RETRY_AFTER,
            );
        }

        if ($response->unauthorized() || $response->forbidden()) {
            throw new TwilioException('The [FORMS_PRO_TWILIO_API_KEY] and [FORMS_PRO_TWILIO_API_SECRET] environment variables are invalid.');
        }

        $errorMessage = $response->json('message')
            ? "Error from Twilio: {$response->json('message')}"
            : "Twilio responded with a [{$response->status()}] status code.";

        throw new TwilioException(
            $errorMessage,
            skippable: in_array((int) $response->json('code'), self::SKIPPABLE_ERRORS, true),
        );
    }
}
