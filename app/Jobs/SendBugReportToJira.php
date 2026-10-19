<?php

namespace App\Jobs;

use App\Support\Portal\PortalBugReportsJira;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A new issue or request on its way to Jira (2026-10-19, user: as it comes in). Tried once here: a failed
 * send is left failed on the report, and the scheduler's run is the one place that tries again, so a
 * retry here and one there can never meet. Carries the report's id and nothing about who sent it.
 */
final class SendBugReportToJira implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Under the database queue's retry_after (300 s), as the mail jobs are.
    public int $timeout = 150;

    public function __construct(public readonly int $reportId) {}

    public function handle(PortalBugReportsJira $jira): void
    {
        $jira->push($this->reportId);
    }
}
