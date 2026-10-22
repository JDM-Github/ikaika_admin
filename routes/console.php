<?php

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\EmailMessage;
use App\Support\Airtable\AirtableClient;
use App\Support\Airtable\PortalAirtableSync;
use App\Support\ApiPath;
use App\Support\Portal\PortalBitbucketPreview;
use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalBudgetEnvelopes;
use App\Support\Portal\PortalBugReports;
use App\Support\Portal\PortalBugReportsJira;
use App\Support\Portal\PortalCache;
use App\Support\Portal\PortalEmailAttachments;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalEmployeeDocuments;
use App\Support\Portal\PortalHolidays;
use App\Support\Portal\PortalHomeLocations;
use App\Support\Portal\PortalJiraClient;
use App\Support\Portal\PortalMailDiagnostics;
use App\Support\Portal\PortalManageRequests;
use App\Support\Portal\PortalPlaces;
use App\Support\Portal\PortalReportLineRepairs;
use App\Support\Portal\PortalReportReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    {--retry-creates : Send again the creates whose answer was lost, once you have checked Airtable holds none of them}
    {--backoff : Leave an action that keeps failing until its next turn (1, 2, 4 ... minutes after its last try, then six-hourly); the schedule passes it}
    {--skip-through= : Settle every unsynced action up to this action id without sending it, e.g. what was recorded before an import; writes nothing to Airtable}', function (PortalAirtableSync $sync) {
    $send = (bool) $this->option('send');
    $check = (bool) $this->option('check');
    $backoff = (bool) $this->option('backoff');

    // A local/Test Server run must never write the PROD bases just because the profile was left on.
    if ($send && config('services.airtable.env') === 'prod' && ! app()->environment('production')) {
        $this->error('Refused: AIRTABLE_ENV=prod from a non-production app. Set AIRTABLE_ENV=test here.');

        return 1;
    }

    $through = $this->option('skip-through');
    if ($through !== null) {
        if (! ctype_digit((string) $through)) {
            $this->error('--skip-through takes an action id, the number after # in the dry run.');

            return 1;
        }
        $this->info($sync->skipThrough((int) $through).' unsynced actions up to #'.$through.' settled without sending; nothing was written to Airtable.');

        return 0;
    }

    // Two sends at once could land an older state over a newer one, so a send runs alone (2026-10-14). The lock
    // outlasts the run's ten-minute budget, and a killed run frees it within fifteen minutes (2026-10-20).
    $lock = $send ? Cache::lock('portal:airtable-sync', 900) : null;
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
        // An idle scheduled minute asks Airtable nothing (2026-10-20): a plan that counts calls is not spent on it.
        if ($send && $backoff && ! $sync->hasDueWork(10)) {
            $this->line('Nothing due.');

            return 0;
        }
        // The wrong base would take every update as a deleted record and re-create it there (2026-10-20).
        $wrongBase = $send || $check ? $sync->wrongBase() : null;
        if ($wrongBase !== null) {
            $this->error($wrongBase);

            return 1;
        }
        // An action younger than ten seconds waits a run: its portal row may still be committing.
        $report = $check
            ? ['items' => $sync->check(), 'counts' => null]
            : $sync->run($send, 10, (bool) $this->option('retry-creates'), backoff: $backoff, budgetSeconds: $send ? 600 : null);
    } catch (Throwable $error) {
        // Unreachable or misconfigured Airtable: a readable reason, never a stack trace or the token.
        $this->error('Airtable sync stopped: '.$error->getMessage().' Anything already sent is recorded; run it again to continue.');

        return 1;
    } finally {
        $lock?->release();
    }

    // When each action was recorded, in Manila time, so a dry run shows what came before an import.
    $recorded = Action::query()->whereIn('id', array_unique(array_column($report['items'], 'actionId')))->pluck('created_at', 'id');
    foreach ($report['items'] as $item) {
        $at = $recorded[$item['actionId']] ?? null;
        $this->line(sprintf(
            '#%d  %s  %-8s  %-22s  %-6s  record %-6s  -> %s%s',
            $item['actionId'],
            $at !== null ? $at->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') : '-',
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
    if ($report['stopped'] ?? false) {
        $this->warn('Stopped after ten minutes so a run never outlives its lock; run it again to send the rest.');
    }
    // The schedule's output goes nowhere, so a send that did not land everything says so in the log.
    if ($send && $counts['failed'] + $counts['blocked'] + $counts['skipped'] > 0) {
        Log::warning('portal.airtable_sync.not_landed', $counts);
    }
    $this->newLine();
    $this->info(implode(' · ', array_map(
        static fn (string $outcome, int $count): string => $outcome.' '.$count,
        array_keys($counts),
        $counts,
    )));

    return $counts['failed'] > 0 ? 1 : 0;
})->purpose('Replay unsynced portal ledger rows into Airtable (dry run unless --send)');

// One-off repair for lines the portal filed before its earn-code picker was fixed (2026-10-22, user).
// Only lines a reports.submitted ledger action names; imported Airtable lines are never touched.
Artisan::command('portal:fix-report-lines
    {--apply : Write the repairs and ledger them for the Airtable sync}', function (PortalReportLineRepairs $repairs): int {
    $pending = $repairs->pending();
    if ($pending === []) {
        $this->info('Nothing to repair.');

        return 0;
    }

    foreach ($pending as $line) {
        $this->line(sprintf(
            '#%d  %s  earn: %s%s  actual work date: %s',
            $line['lineId'],
            $line['reportDate'] ?? '-',
            $line['currentEarn'] ?? '-',
            $line['needsEarn'] ? ' -> '.$line['targetEarn'] : ' (unchanged)',
            $line['needsDate'] ? 'empty -> '.($line['reportDate'] ?? '-') : ($line['actualWorkDate'] ?? '-'),
        ));
    }

    if (! $this->option('apply')) {
        $this->warn(count($pending).' line(s) would be repaired. Run with --apply to write and ledger them.');

        return 0;
    }

    $this->info($repairs->apply($pending).' line(s) repaired and ledgered for the Airtable sync.');

    return 0;
})->purpose('Repair portal-filed report lines: wrong earn code and empty Actual Work Date (dry run unless --apply)');

// The Calendar base is the source of holidays now; the estimator calendar is no longer read (Phase 2).
Artisan::command('portal:sync-calendar
    {--dry-run : Show what would change; write nothing}', function (AirtableClient $airtable, PortalHolidays $holidays): int {
    try {
        $earnRecords = $airtable->records('Earn Codes', 'calendar');
        $holidayRecords = $airtable->records('Holidays', 'calendar');
    } catch (Throwable $error) {
        $this->error('Calendar pull stopped: '.$error->getMessage());

        return 1;
    }

    $descriptions = [];
    foreach ($earnRecords as $record) {
        $description = $record['fields']['Description'] ?? null;
        if (is_string($description) && trim($description) !== '') {
            $descriptions[$record['id']] = $description;
        }
    }

    $desired = [];
    $skipped = 0;
    foreach ($holidayRecords as $record) {
        $date = $record['fields']['Date'] ?? null;
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
            $skipped++;

            continue;
        }
        $date = substr($date, 0, 10);
        $name = $record['fields']['Name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        $links = $record['fields']['Earn Code'] ?? null;
        $earnId = is_array($links) && is_string($links[0] ?? null) ? $links[0] : null;
        $desired[$record['id']] = [
            'airtable_record_id' => $record['id'],
            'holiday_date' => $date,
            'name' => $name === '' ? null : $name,
            'type' => PortalHolidays::classifyDescription($earnId === null ? null : ($descriptions[$earnId] ?? null)),
        ];
    }

    $existing = DB::connection('portal')->table('calendar_holidays')->get()->keyBy('airtable_record_id');
    $inserts = [];
    $updates = [];
    foreach ($desired as $id => $row) {
        $current = $existing[$id] ?? null;
        if ($current === null) {
            $inserts[$id] = $row;

            continue;
        }
        if (substr((string) $current->holiday_date, 0, 10) !== $row['holiday_date']
            || (string) ($current->name ?? '') !== (string) ($row['name'] ?? '')
            || (string) $current->type !== $row['type']) {
            $updates[$id] = $row;
        }
    }
    $deletes = [];
    foreach ($existing as $id => $row) {
        if ($id !== '' && ! isset($desired[$id])) {
            $deletes[] = $id;
        }
    }

    $this->line(sprintf(
        '%d holiday(s) in Calendar: %d new, %d changed, %d gone%s.',
        count($desired),
        count($inserts),
        count($updates),
        count($deletes),
        $skipped > 0 ? ', '.$skipped.' without a date skipped' : '',
    ));
    if ($this->option('dry-run')) {
        $this->warn('Dry run: nothing written.');

        return 0;
    }

    $now = now();
    DB::connection('portal')->transaction(function () use ($inserts, $updates, $deletes, $now): void {
        foreach ($inserts as $row) {
            DB::connection('portal')->table('calendar_holidays')->insert([...$row, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($updates as $id => $row) {
            DB::connection('portal')->table('calendar_holidays')->where('airtable_record_id', $id)->update([
                'holiday_date' => $row['holiday_date'],
                'name' => $row['name'],
                'type' => $row['type'],
                'updated_at' => $now,
            ]);
        }
        if ($deletes !== []) {
            DB::connection('portal')->table('calendar_holidays')->whereIn('airtable_record_id', $deletes)->delete();
        }
    });
    $holidays->bumpCache();
    PortalCache::bump();
    $this->info('Calendar holidays synced: '.count($inserts).' added, '.count($updates).' updated, '.count($deletes).' removed.');

    return 0;
})->purpose('Pull the Calendar base holidays into the portal mirror (Airtable is read only)');

// The Calendar Earn Codes are the source; Users & Projects holds a read-only synced copy. Match the
// local rows to both record ids by description, only filling ids that are missing (2026-10-22, user).
Artisan::command('portal:map-earn-codes
    {--dry-run : Show what would be mapped; write nothing}', function (AirtableClient $airtable): int {
    try {
        $calendarRecords = $airtable->records('Earn Codes', 'calendar');
        $mainRecords = $airtable->records('Earn Codes', 'main');
    } catch (Throwable $error) {
        $this->error('Earn code mapping stopped: '.$error->getMessage());

        return 1;
    }

    $byDescription = static function (array $records): array {
        $ids = [];
        foreach ($records as $record) {
            $description = $record['fields']['Description'] ?? null;
            if (! is_string($description) || trim($description) === '') {
                continue;
            }
            $key = strtolower((string) preg_replace('/\s+/u', ' ', trim($description)));
            $ids[$key] ??= $record['id'];
        }

        return $ids;
    };
    $calendarIds = $byDescription($calendarRecords);
    $mainIds = $byDescription($mainRecords);

    $calendarUpdates = [];
    $mainUpdates = [];
    $unmatched = [];
    foreach (DB::connection('portal')->table('earn_codes')->orderBy('id')->get(['id', 'description', 'airtable_record_id', 'calendar_airtable_record_id']) as $row) {
        $key = strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $row->description)));
        $calendarId = $calendarIds[$key] ?? null;
        $mainId = $mainIds[$key] ?? null;
        if ($calendarId !== null && (string) ($row->calendar_airtable_record_id ?? '') === '') {
            $calendarUpdates[(int) $row->id] = $calendarId;
        }
        if ($mainId !== null && (string) ($row->airtable_record_id ?? '') === '') {
            $mainUpdates[(int) $row->id] = $mainId;
        }
        if ($calendarId === null || $mainId === null) {
            $unmatched[] = '#'.$row->id.' '.trim((string) $row->description);
        }
    }

    $this->line(count($calendarUpdates).' Calendar id(s) and '.count($mainUpdates).' Users & Projects id(s) to map.');
    foreach ($unmatched as $line) {
        $this->warn('  no match: '.$line);
    }
    if ($this->option('dry-run')) {
        $this->warn('Dry run: nothing written.');

        return 0;
    }

    DB::connection('portal')->transaction(function () use ($calendarUpdates, $mainUpdates): void {
        foreach ($calendarUpdates as $id => $recordId) {
            DB::connection('portal')->table('earn_codes')->where('id', $id)->update(['calendar_airtable_record_id' => $recordId]);
        }
        foreach ($mainUpdates as $id => $recordId) {
            DB::connection('portal')->table('earn_codes')->where('id', $id)->update(['airtable_record_id' => $recordId]);
        }
    });
    $this->info('Earn code ids mapped: '.count($calendarUpdates).' Calendar, '.count($mainUpdates).' Users & Projects.');

    return 0;
})->purpose('Match portal earn codes to their Calendar and Users & Projects record ids by description');

// The Client Information base is the source; Users & Projects holds a read-only synced copy. Match
// the local clients to both record ids by name, filling only the ids that are missing.
Artisan::command('portal:map-clients
    {--dry-run : Show what would be mapped; write nothing}', function (AirtableClient $airtable): int {
    try {
        $clientRecords = $airtable->records('Clients', 'clients');
        $mainRecords = $airtable->records('Clients', 'main');
    } catch (Throwable $error) {
        $this->error('Client mapping stopped: '.$error->getMessage());

        return 1;
    }

    $byName = static function (array $records): array {
        $ids = [];
        foreach ($records as $record) {
            $name = $record['fields']['Name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $key = strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
            $ids[$key] ??= $record['id'];
        }

        return $ids;
    };
    $clientIds = $byName($clientRecords);
    $mainIds = $byName($mainRecords);

    $clientUpdates = [];
    $mainUpdates = [];
    $unmatched = [];
    foreach (DB::connection('portal')->table('clients')->orderBy('id')->get(['id', 'name', 'airtable_record_id', 'client_info_airtable_record_id']) as $row) {
        $key = strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $row->name)));
        $clientId = $clientIds[$key] ?? null;
        $mainId = $mainIds[$key] ?? null;
        if ($clientId !== null && (string) ($row->client_info_airtable_record_id ?? '') === '') {
            $clientUpdates[(int) $row->id] = $clientId;
        }
        if ($mainId !== null && (string) ($row->airtable_record_id ?? '') === '') {
            $mainUpdates[(int) $row->id] = $mainId;
        }
        if ($clientId === null || $mainId === null) {
            $unmatched[] = '#'.$row->id.' '.trim((string) $row->name);
        }
    }

    $this->line(count($clientUpdates).' Client Information id(s) and '.count($mainUpdates).' Users & Projects id(s) to map.');
    foreach ($unmatched as $line) {
        $this->warn('  no match: '.$line);
    }
    if ($this->option('dry-run')) {
        $this->warn('Dry run: nothing written.');

        return 0;
    }

    DB::connection('portal')->transaction(function () use ($clientUpdates, $mainUpdates): void {
        foreach ($clientUpdates as $id => $recordId) {
            DB::connection('portal')->table('clients')->where('id', $id)->update(['client_info_airtable_record_id' => $recordId]);
        }
        foreach ($mainUpdates as $id => $recordId) {
            DB::connection('portal')->table('clients')->where('id', $id)->update(['airtable_record_id' => $recordId]);
        }
    });
    $this->info('Client ids mapped: '.count($clientUpdates).' Client Information, '.count($mainUpdates).' Users & Projects.');

    return 0;
})->purpose('Match portal clients to their Client Information and Users & Projects record ids by name');

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
        if ($error->getCode() === 401 && trim((string) config('services.jira.cloud_id')) === '') {
            $cloud = $client->siteCloudId();
            $this->line('A scoped API token is refused at the site: set JIRA_CLOUD_ID'.($cloud === null ? ' (the cloudId at '.rtrim((string) config('services.jira.base_url'), '/').'/_edge/tenant_info)' : '='.$cloud).', or use a classic token.');
        }

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
// Holidays now come from the Calendar base; pull them into the portal mirror every hour (Phase 2).
Schedule::command('portal:sync-calendar')->hourly()->withoutOverlapping()
    ->when(fn (): bool => is_string(config('services.airtable.bases.calendar')) && config('services.airtable.bases.calendar') !== '');
// New Calendar codes reach Users & Projects through Airtable's synced table; this catches their ids.
Schedule::command('portal:map-earn-codes')->hourly()->withoutOverlapping()
    ->when(fn (): bool => is_string(config('services.airtable.bases.calendar')) && config('services.airtable.bases.calendar') !== '');
// New Client Information records reach Users & Projects through Airtable's synced table.
Schedule::command('portal:map-clients')->hourly()->withoutOverlapping()
    ->when(fn (): bool => is_string(config('services.airtable.bases.clients')) && config('services.airtable.bases.clients') !== '');
// Jira's backstop: what failed or waits is sent again, and every linked issue is read back, if a delivery was lost.
Schedule::command('portal:jira-sync')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('portal:geocode-homes')->daily()->withoutOverlapping();
Schedule::command('portal:remind-missing-reports')->weekdays()->at('18:00')->timezone('Asia/Manila')->withoutOverlapping();
// The Airtable sync, off until AIRTABLE_SYNC_SCHEDULED=true (2026-10-20). Every tick, so it follows the cron's cadence.
Schedule::command('portal:airtable-sync --send --backoff')->everyMinute()->withoutOverlapping(15)->runInBackground()
    ->when(fn (): bool => (bool) config('services.airtable.scheduled'));

// The mail queue's worker. The server runs no daemon, so the minute cron starts one that leaves when the queue is
// empty or after 55 s, before the next one starts; two that do meet cannot take the same job.
Schedule::command('queue:work --stop-when-empty --max-time=55')->everyMinute()->runInBackground();
