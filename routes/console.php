<?php

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailMessage;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalEmailAttachments;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalMailDiagnostics;
use App\Support\Portal\PortalManageRequests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

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

/*
 * One test email through the same mailable Send Email uses, to check the .env mail settings. It
 * names the mailer, host, port and sender it used, never the username or password.
 *
 * --message resends one stored portal email exactly as it was built (body, footer, formats, sender
 * name, Reply-To) to the test address only, so a send that never arrived can be tried again alone.
 * The server's own acceptance line is printed so a host can trace it; the rest of the transcript is
 * not, because it carries the login.
 */
Artisan::command('portal:mail-test
    {to=jdmpega@ikaikabim.com : Who receives the test}
    {--message= : Resend this outbox row exactly as it was built}', function (): int {
    $to = trim((string) $this->argument('to'));
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        $this->error('That is not an email address: '.$to);

        return 1;
    }

    $mailer = (string) config('mail.default');
    $this->line(sprintf(
        'Mailer %s, host %s, port %s, from %s',
        $mailer,
        (string) config("mail.mailers.{$mailer}.host", '-'),
        (string) config("mail.mailers.{$mailer}.port", '-'),
        (string) config('mail.from.address'),
    ));
    if ($mailer === 'log') {
        $this->warn('MAIL_MAILER is log: the email is written to storage/logs, not sent.');
    }

    $messageId = $this->option('message');
    if ($messageId !== null) {
        $row = EmailMessage::query()->find((int) $messageId);
        if (! $row instanceof EmailMessage) {
            $this->error('There is no outbox row #'.$messageId.'.');

            return 1;
        }
        $mail = new PortalEmailMail(
            (string) $row->subject,
            (string) $row->body,
            is_string($row->footer_body) ? $row->footer_body : null,
            PortalEmailMarkup::format($row->format) ?? PortalEmailMarkup::FORMAT_MARKUP,
            PortalEmailMarkup::format($row->footer_format) ?? PortalEmailMarkup::FORMAT_MARKUP,
            is_string($row->from_name) ? $row->from_name : null,
            is_string($row->reply_to) ? $row->reply_to : null,
        );
        $this->line(sprintf(
            'Resending #%d "%s" (%s, %s, %s)',
            (int) $row->getKey(),
            (string) $row->subject,
            (string) ($row->format ?? 'markup'),
            $row->from_name === null ? 'as the portal' : 'sent as a person',
            $row->footer_body === null ? 'no footer' : 'with footer',
        ));
    } else {
        $mail = PortalEmailMessages::testMail();
    }

    $origin = 'console'.($messageId === null ? '' : ' --message='.$messageId);
    try {
        $sent = Mail::to($to)->send($mail);
    } catch (Throwable $error) {
        PortalMailDiagnostics::record($origin, $to, null, $error);
        $this->error('Not sent: '.$error->getMessage());
        $this->line('Full report: storage/'.PortalMailDiagnostics::LOG_PATH);

        return 1;
    }
    PortalMailDiagnostics::record($origin, $to, $sent, null);
    $this->line('Full report: storage/'.PortalMailDiagnostics::LOG_PATH);

    $this->info('Sent to '.$to.'. Check the inbox, Junk and Other.');
    $this->line('Server reply: '.(PortalEmailMessages::acceptance($sent) ?? 'not reported').'  Message-ID: '.($sent?->getMessageId() ?? '-'));

    return 0;
})->purpose('Send one test email with the current mail settings');

// Each attach clears what was abandoned too; this is for a scheduler, or after a quiet stretch.
Artisan::command('portal:prune-email-attachments', function (PortalEmailAttachments $attachments): int {
    $pruned = $attachments->prune();
    $this->info($pruned === 1 ? 'Cleared 1 attachment that was never sent.' : 'Cleared '.$pruned.' attachments that were never sent.');

    return 0;
})->purpose('Delete Send Email attachments staged more than a day ago and never sent');

Artisan::command('portal:bitbucket-sync', function (PortalBitbucketSync $sync): int {
    $outcomes = $sync->syncLinked();
    if ($outcomes === []) {
        $this->info('No project links a Bitbucket repository yet.');

        return 0;
    }
    foreach ($outcomes as $repository => $outcome) {
        is_string($outcome)
            ? $this->error($repository.': '.$outcome)
            : $this->info($repository.': '.$outcome['listed'].' new commits, '.$outcome['counted'].' counted');
    }

    return in_array(true, array_map('is_string', $outcomes), true) ? 1 : 0;
})->purpose('Read new commits and their lines from every Bitbucket repository a project links');

// The backstop for a missed push webhook, and what finishes counting a long history; needs the server's schedule:run cron.
Schedule::command('portal:bitbucket-sync')->hourly()->withoutOverlapping();
