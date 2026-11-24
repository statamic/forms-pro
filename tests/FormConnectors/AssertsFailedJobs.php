<?php

namespace Tests\FormConnectors;

trait AssertsFailedJobs
{
    protected function assertJobFails(object $job, string $message): void
    {
        app()->call([$job->withFakeQueueInteractions(), 'handle']);

        $job->assertFailed();
        $job->assertNotReleased();
        $this->assertStringContainsString($message, $job->job->failedWith->getMessage());
    }
}
