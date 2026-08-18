<?php

namespace Statamic\FormsPro\Listeners;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Statamic\Events\FormSubmitted;

class VerifyTurnstile
{
    public function handle(FormSubmitted $event): void
    {
        $siteKey = config('forms-pro.turnstile.site_key');
        $secretKey = config('forms-pro.turnstile.secret_key');

        if (! $siteKey || ! $secretKey) {
            return;
        }

        $token = (string) request()->input('cf-turnstile-response');

        if (! $token || ! $this->verifiedByCloudflare($token, $secretKey)) {
            throw ValidationException::withMessages([
                'cf-turnstile-response' => __('forms-pro::validation.cloudflare_turnstile_response'),
            ]);
        }
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
