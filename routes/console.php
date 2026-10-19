<?php

use App\Modules\Portal\Models\EmailMessage;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\ApiPath;
use App\Support\Portal\PortalBitbucketPreview;
use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalBugReports;
use App\Support\Portal\PortalBugReportsJira;
use App\Support\Portal\PortalEmailAttachments;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalEmployeeDocuments;
use App\Support\Portal\PortalHomeLocations;
use App\Support\Portal\PortalJiraClient;
use App\Support\Portal\PortalMailDiagnostics;
use App\Support\Portal\PortalManageRequests;
use App\Support\Portal\PortalPlaces;
use App\Support\Portal\PortalReportReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dry run unless --send is given: the default must never write to Airtable by accident.
Artisan::command('portal:airtable-sync
    {--send : Write to Airtable; without it nothing is written anywhere}
    {--check : Test every mapping against one real row each; writes nothing}
    {--retry-creates : Send again the creates whose answer was lost, once you have checked Airtable holds none of them}', function (PortalAirtableSync $sync) {
    $send = (bool) $this->option('send');
    $check = (bool) $this->option('check');
    // Two sends at once could land an older state over a newer one, so a send runs alone (2026-10-14).
    $lock = $send ? Cache::lock('portal:airtable-sync', 3600) : null;
    if ($lock !== null && ! $lock->get()) {
        $this->error('Another Airtable send is already running; nothing was sent.');

        return 1;
    }
    $this->info(match (true) {
        $check => 'CHECK -- every mapping against the live base; nothing will be written.',
        $send => 'Sending unsynced portal actions to Airtable.',
        default => 'DRY RUN -- nothing will be written to Airtable or the ledger.',
    });

    try {
        // An action younger than ten seconds waits a run: its portal row may still be committing.
        $report = $check
            ? ['items' => $sync->check(), 'counts' => null]
            : $sync->run($send, 10, (bool) $this->option('retry-creates'));
    } catch (Throwable $error) {
        // Unreachable or misconfigured Airtable: a readable reason, never a stack trace or the token.
        $this->error('Airtable sync stopped: '.$error->getMessage().' Anything already sent is recorded; run it again to continue.');

        return 1;
    } finally {
        $lock?->release();
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
        $mail = PortalEmailMessages::mailFromRow($row);
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

// Promotion copies a candidate's files to Cloudinary since 2026-10-12; this copies them for anyone promoted before.
Artisan::command('portal:copy-registration-documents', function (PortalEmployeeDocuments $documents): int {
    $outcome = $documents->copyAllPromoted();
    $this->info($outcome['copied'].' copied, '.$outcome['skipped'].' already on Cloudinary, '.$outcome['failed'].' failed.');

    return $outcome['failed'] > 0 ? 1 : 0;
})->purpose('Copy the registration files of promoted employees to Cloudinary as their documents');

// The registration form's address lists (2026-10-13): loaded by their migration, and again from a newer copy.
Artisan::command('portal:import-places', function (PortalPlaces $places): int {
    $loaded = $places->import();
    $this->info($loaded['psgc_provinces'].' provinces, '.$loaded['psgc_cities'].' cities and municipalities, '.$loaded['psgc_barangays'].' barangays.');

    return 0;
})->purpose('Load the Philippine provinces, cities and barangays from database/data/psgc');

// Each attach clears what was abandoned too; this is for a scheduler, or after a quiet stretch.
Artisan::command('portal:prune-email-attachments', function (PortalEmailAttachments $attachments): int {
    $pruned = $attachments->prune();
    $this->info($pruned === 1 ? 'Cleared 1 attachment that was never sent.' : 'Cleared '.$pruned.' attachments that were never sent.');

    return 0;
})->purpose('Delete Send Email attachments staged more than a day ago and never sent');

Artisan::command('portal:bitbucket-sync
    {--recount : Count the lines of every commit again, after the counting rules change}', function (PortalBitbucketSync $sync): int {
    if ($this->option('recount')) {
        $this->info($sync->recount().' commits will be counted again.');
    }
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

Artisan::command('portal:bitbucket-preview
    {repository : A bitbucket.org link, SSH address or workspace/repository}
    {--day= : The day to show, YYYY-MM-DD in company time; today when left out}
    {--author= : Only this author email}', function (PortalBitbucketPreview $preview): int {
    $day = (string) ($this->option('day') ?? PortalBitbucketSync::companyToday());
    $author = $this->option('author');
    try {
        $result = $preview->day((string) $this->argument('repository'), $day, is_string($author) ? $author : null);
    } catch (RuntimeException $error) {
        $this->error($error->getMessage());

        return 1;
    }

    $this->info($result['repository'].' on '.$result['day'].' ('.PortalBitbucketSync::COMPANY_ZONE.' time)');
    if ($result['commits'] === []) {
        $this->line('No commits that day.');
    }
    foreach ($result['commits'] as $commit) {
        $this->newLine();
        $this->line(sprintf(
            '<comment>%s</comment>  %s  %s  %s',
            substr($commit['hash'], 0, 7),
            $commit['time'],
            $commit['authorName'] ?? $commit['authorEmail'] ?? 'Unknown',
            $commit['message'],
        ));
        if ($commit['isMerge']) {
            $this->line('    merge commit: never counted, its lines stay with whoever wrote them');

            continue;
        }
        foreach ($commit['files'] as $file) {
            $this->line(sprintf('    +%d -%d  %s%s', $file['added'], $file['removed'], $file['path'], $file['generated'] ? '  (generated, not counted)' : ''));
        }
        $this->line(sprintf('    counted: +%d -%d in %d files', $commit['lines']['added'], $commit['lines']['removed'], $commit['lines']['files']));
    }

    $this->newLine();
    $this->table(
        ['Author', 'Employee', 'Commits', 'Merges', 'Lines +', 'Lines -', 'Files'],
        array_map(static fn (array $row): array => array_values($row), $result['authors']),
    );
    if (! $result['complete']) {
        $this->warn('Stopped after '.$result['pagesRead'].' pages of history; an older day may be missing commits.');
    }
    $this->line('Dry run: nothing was saved.');

    return 0;
})->purpose('Show what the Bitbucket sync would read for one repository and one day, without saving anything');

// The backstop for a missed push webhook, and what finishes counting a long history; needs the server's schedule:run cron.
// Manage / Map: places each person's new or changed address; one Nominatim request a second.
Artisan::command('portal:geocode-homes', function (PortalHomeLocations $homes): int {
    $counts = $homes->refresh();
    $this->info("Placed {$counts['placed']}, could not place {$counts['failed']}, unchanged {$counts['unchanged']}.");

    return 0;
})->purpose('Turn every address into a point for Manage / Map');

// Weekdays at 18:00 Manila, the old cron's time (schedule below): replaces the WordPress cron that read Airtable.
Artisan::command('portal:remind-missing-reports
    {--dry-run : List who would be reminded and for which days; sends nothing}
    {--to= : Send one sample reminder to this address only, outside the outbox}', function (PortalReportReminders $reminders): int {
    $plan = $reminders->missing(now());
    $summaries = $reminders->summaries($plan);
    foreach ($plan as $row) {
        $this->line($row['name'].' <'.$row['email'].'>: '.implode(', ', $row['days']));
    }
    foreach ($summaries as $summary) {
        $this->line('Summary to '.$summary['name'].' ('.$summary['department'].'): '.implode(', ', array_column($summary['members'], 'name')));
    }
    $this->info(count($plan).' '.(count($plan) === 1 ? 'person is' : 'people are').' missing a report; '.count($summaries).' department '.(count($summaries) === 1 ? 'summary' : 'summaries').'.');

    $to = trim((string) $this->option('to'));
    if ($this->option('dry-run')) {
        return 0;
    }
    if ($to !== '') {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('That is not an email address.');

            return 1;
        }
        if ($plan === []) {
            $this->warn('Nobody is missing a report, so there is nothing to sample.');

            return 0;
        }
        try {
            $reminders->sample($to, $reminders->letter($plan[0]['days']));
            if ($summaries !== []) {
                $reminders->sample($to, $reminders->summaryLetter($summaries[0]));
            }
        } catch (Throwable $error) {
            $this->error('Not sent: '.$error->getMessage());

            return 1;
        }
        $this->info('Samples sent to '.$to.': '.$plan[0]['name']."'s reminder".($summaries === [] ? '' : ' and the '.$summaries[0]['department'].' summary').'.');
    } else {
        foreach ($plan as $row) {
            $reminders->remind($row['employee'], $row['days']);
        }
        foreach ($summaries as $summary) {
            $reminders->summarize($summary);
        }
        Log::info('portal.report-reminders.sent', ['count' => count($plan), 'summaries' => count($summaries)]);
    }
    // A console run has no response to send the mail after, so the queue is worked here.
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 3]);

    return 0;
})->purpose('Email everyone missing a report in the last five work days, and each department head their team');

Artisan::command('portal:jira-sync', function (PortalBugReportsJira $jira, PortalJiraClient $client): int {
    if (! $jira->isConfigured()) {
        $this->info('Jira is not configured here; nothing to send or read.');

        return 0;
    }
    try {
        $done = $jira->run();
    } catch (RuntimeException $error) {
        $this->error($error->getMessage());

        return 1;
    }
    $this->info("Sent {$done['sent']}, failed {$done['failed']}, read back {$done['synced']}, gone from Jira {$done['deleted']}.");
    $connection = $client->connection();
    if (in_array($connection['state'], [PortalJiraClient::REFUSED, PortalJiraClient::UNREACHABLE], true)) {
        $this->error('Jira: '.$connection['message']);

        return 1;
    }

    return 0;
})->purpose('Send the issues and requests waiting for Jira, and read every linked one back');

Artisan::command('portal:jira-check', function (PortalJiraClient $client, PortalBugReportsJira $jira): int {
    $missing = $client->missingSettings();
    if ($missing !== []) {
        $this->error('Jira is not configured: set '.implode(', ', $missing).' (JIRA_BASE_URL as https://<site>.atlassian.net).');

        return 1;
    }
    $failed = false;
    try {
        $account = $client->myself();
        $this->info('Account: '.$account['displayName'].' ('.$account['accountId'].')');
        $project = $client->project();
        $this->info('Project: '.$project['key'].' - '.$project['name']);
        foreach (PortalBugReports::KINDS as $kind => $words) {
            $type = $jira->issueTypeFor($kind);
            if ($type['id'] === null) {
                $failed = true;
                $this->error($words['label'].': no issue type '.$type['wanted'].' or Task. The project has: '.implode(', ', array_column($client->issueTypes(), 'name')).'.');
            } else {
                $this->info($words['label'].' -> '.$type['name'].($type['name'] === $type['wanted'] ? '' : ' (no '.$type['wanted'].' in the project)'));
            }
        }
    } catch (RuntimeException $error) {
        $this->error($error->getMessage());

        return 1;
    }
    $this->info('New ones go to Jira on their own: '.($jira->autoSend() ? 'on' : 'off'));
    $hasSecret = trim((string) config('services.jira.webhook_secret')) !== '';
    $hasSecret ? $this->info('Webhook secret: set') : $this->warn('Webhook secret: not set - every delivery will be refused until JIRA_WEBHOOK_SECRET is.');
    $this->line('');
    $this->line('A Jira admin registers the webhook in Jira settings > System > WebHooks:');
    $this->line('  URL:    '.url(ApiPath::join((string) config('products.channel'), 'jira', 'webhook')));
    $this->line('  Events: Issue updated, Issue deleted');
    $this->line('  JQL:    project = '.$client->projectKey().' AND labels = '.config('services.jira.label'));
    $this->line('  Secret: the value of JIRA_WEBHOOK_SECRET');

    return $failed ? 1 : 0;
})->purpose('Check the Jira account, project and issue types, and print the webhook to register');

Schedule::command('portal:bitbucket-sync')->hourly()->withoutOverlapping();
// Jira's backstop: what failed or waits is sent again, and every linked issue is read back, if a delivery was lost.
Schedule::command('portal:jira-sync')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('portal:geocode-homes')->daily()->withoutOverlapping();
Schedule::command('portal:remind-missing-reports')->weekdays()->at('18:00')->timezone('Asia/Manila')->withoutOverlapping();

// The mail queue's worker. The server runs no daemon, so the minute cron starts one that leaves when the queue is
// empty or after 55 s, before the next one starts; two that do meet cannot take the same job.
Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->runInBackground();
