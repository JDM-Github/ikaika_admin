<?php

use App\Support\Airtable\PortalAirtableSync;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalManageRequests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dry run unless --send is given: the default must never write to Airtable by accident.
Artisan::command('portal:airtable-sync
    {--send : Write to Airtable; without it nothing is written anywhere}
    {--check : Test every mapping against one real row each; writes nothing}', function (PortalAirtableSync $sync) {
    $send = (bool) $this->option('send');
    $check = (bool) $this->option('check');
    $this->info(match (true) {
        $check => 'CHECK -- every mapping against the live base; nothing will be written.',
        $send => 'Sending unsynced portal actions to Airtable.',
        default => 'DRY RUN -- nothing will be written to Airtable or the ledger.',
    });

    try {
        $report = $check
            ? ['items' => $sync->check(), 'counts' => null]
            : $sync->run($send);
    } catch (Throwable $error) {
        // Unreachable or misconfigured Airtable: a readable reason, never a stack trace or the token.
        $this->error('Airtable sync stopped: '.$error->getMessage().' Anything already sent is recorded; run it again to continue.');

        return 1;
    }

    foreach ($report['items'] as $item) {
        $this->line(sprintf(
            '#%d  %-8s  %-22s  %-6s  record %-6s  -> %s%s',
            $item['actionId'],
            strtoupper($item['outcome']),
            (string) $item['resource'],
            $item['actionType'],
            (string) $item['recordId'],
            (string) $item['table'],
            $item['airtableId'] !== null ? ' '.$item['airtableId'] : '',
        ));
        if ($item['fields'] !== []) {
            $this->line('      fields: '.json_encode($item['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        foreach ($item['problems'] as $problem) {
            $this->warn('      ! '.$problem);
        }
    }

    $counts = $report['counts'];
    if ($counts === null) {
        return 0;
    }
    $this->newLine();
    $this->info(implode(' · ', array_map(
        static fn (string $outcome, int $count): string => $outcome.' '.$count,
        array_keys($counts),
        $counts,
    )));

    return $counts['failed'] > 0 ? 1 : 0;
})->purpose('Replay unsynced portal ledger rows into Airtable (dry run unless --send)');
/*
 * Books the reimbursement charges the portal recorded before a charge carried the claimant's team
 * account, so they come off that team's balance too. Safe to run again: a booked charge is skipped.
 */
Artisan::command('portal:book-team-charges', function (PortalBudgetEnvelopes $budget, PortalManageRequests $requests): int {
    if (! $budget->isAvailable()) {
        $this->warn('The transaction tracker is switched off here, so there is nothing to book.');

        return 0;
    }

    $charges = $budget->withinLedger(fn (): array => $budget->bookUnlinkedCharges(
        fn (int $claimId): ?string => $requests->claimantDepartment($claimId),
    ));
    if ($charges === []) {
        $this->info('Every reimbursement charge is already booked to a team account.');

        return 0;
    }

    foreach ($charges as $charge) {
        $this->line($charge['accountName'] === null
            ? sprintf('Charge %d (claim %d): no team account for %s, left as it was.', $charge['transactionId'], $charge['claimId'], $charge['team'] ?? 'an unknown team')
            : sprintf('Charge %d (claim %d): booked to %s (%s).', $charge['transactionId'], $charge['claimId'], $charge['accountName'], $charge['team']));
    }

    return 0;
})->purpose('Book the portal\'s reimbursement charges to the claimant team\'s account');
