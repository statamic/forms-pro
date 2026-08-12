<?php

namespace Statamic\FormsPro\FormConnections\Slack;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Statamic\Contracts\Forms\Submission;
use Statamic\Sites\Site;

class SendSlackMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public Submission $submission, public Site $site, public array $config) {}

    public function backoff(): array
    {
        return [10, 30, 120, 600];
    }

    public function handle(SlackClient $slack): void
    {
        $submission = $this->submission->form()->submission($this->submission->id()) ?? $this->submission;

        try {
            $slack->postMessage(
                new MessageBuilder($submission, $this->site, $this->config)->build()
            );
        } catch (SlackException $e) {
            match (true) {
                $e->isRetryable() => $this->release($e->retryAfter()),
                default => throw $e,
            };
        }
    }
}
