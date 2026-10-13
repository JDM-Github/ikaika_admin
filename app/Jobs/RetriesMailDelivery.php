<?php

namespace App\Jobs;

use Illuminate\Queue\Jobs\SyncJob;

/**
 * The retry rule every queued portal email shares: a relay that refuses a message gets two more
 * tries, a minute and then five minutes later, before the email counts as failed.
 *
 * The job decides this itself instead of throwing, so the sync queue (local runs, tests) still
 * finishes inside the request: it cannot hold a job for later, so there the first failure is final.
 */
trait RetriesMailDelivery
{
    private const RETRY_DELAYS = [60, 300];

    // One more than the delays: the first try plus one per delay.
    public int $tries = 3;

    // The relay waits up to 120 s for a message with files; config/queue.php's retry_after stays above this.
    public int $timeout = 150;

    private function retryLater(): bool
    {
        if ($this->job === null || $this->job instanceof SyncJob) {
            return false;
        }
        $delay = self::RETRY_DELAYS[$this->attempts() - 1] ?? null;
        if ($delay === null) {
            return false;
        }
        $this->release($delay);

        return true;
    }
}
