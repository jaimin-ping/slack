<?php

namespace App\Jobs\AI\Concerns;

/**
 * With the "sync" queue a job runs inside the request that queued it, after the database commit.
 * A retry is impossible there, and an AI failure must never turn into an error for the person who just
 * sent a message, so failures (already recorded on the run) stop at the job.
 */
trait RunsSafelyInline
{
    protected function runningInline(): bool
    {
        return $this->job === null || $this->job->getConnectionName() === 'sync';
    }
}
