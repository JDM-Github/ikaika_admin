<?php

use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalManageRequests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
