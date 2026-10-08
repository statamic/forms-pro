<?php

namespace Statamic\FormsPro\FormConnectors\Twilio;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Forms\Submission;
use Statamic\Facades\Antlers;
use Statamic\Facades\Parse;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class SendTwilioMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(TwilioClient $twilio): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        $to = $this->recipient($submission);
        $body = $this->body($submission);

        if (! $to || ! $body) {
            return;
        }

        try {
            $twilio->sendMessage([...$this->sender(), 'To' => $to, 'Body' => $body]);
        } catch (TwilioException $e) {
            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                $e->isSkippable() => Log::info("Skipped texting [{$to}]: {$e->getMessage()}"),
                default => $this->fail($e),
            };
        }
    }

    private function recipient(Submission $submission): ?string
    {
        $to = Arr::get($this->config, 'to');

        if (Str::startsWith($to, 'field:')) {
            $to = $submission->get(Str::after($to, 'field:'));
        }

        if (blank($to)) {
            return null;
        }

        if ($number = $this->normalize((string) $to)) {
            return $number;
        }

        Log::info("Couldn't send to [{$to}]. The number isn't in a format Twilio can use.");

        return null;
    }

    private function normalize(string $value): ?string
    {
        $value = trim($value);
        $digits = preg_replace('/\D/', '', $value);

        if (blank($digits)) {
            return null;
        }

        $countryCode = Arr::get($this->config, 'country_code');

        $number = match (true) {
            Str::startsWith($value, '+') => '+'.$digits,
            Str::startsWith($digits, '00') => '+'.substr($digits, 2),
            filled($countryCode) => $countryCode.ltrim($digits, '0'),
            default => $digits,
        };

        return preg_match('/^\+[1-9]\d{1,14}$/', $number) ? $number : null;
    }

    private function body(Submission $submission): ?string
    {
        $body = Antlers::parse(
            Parse::config($this->config['body']),
            $submission->toAugmentedArray()
        );

        return Str::limit(trim((string) $body), 1599, '…') ?: null;
    }

    private function sender(): array
    {
        $from = $this->config['from'];

        return Str::startsWith($from, 'MG')
            ? ['MessagingServiceSid' => $from]
            : ['From' => $from];
    }
}
