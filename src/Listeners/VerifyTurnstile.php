<?php

namespace Statamic\FormsPro\Listeners;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Statamic\Events\FormSubmitted;

class VerifyTurnstile
{
    public function handle(FormSubmitted $event): ?bool
    {
        $siteKey = config('forms-pro.turnstile.site_key');
        $secretKey = config('forms-pro.turnstile.secret_key');

        if (! $siteKey || ! $secretKey) {
            return null;
        }

        $token = request()->input('cf-turnstile-response');

        if (! is_string($token) || $token === '') {
            return false;
        }

        return $this->verifiedByCloudflare($token, $secretKey) ? null : false;
    }

    private function verifiedByCloudflare(string $token, string $secretKey): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secretKey,
                    'response' => $token,
                    'remoteip' => request()->ip(),
                ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->json('success') === true;
    }
}
