<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Issues & Requests and Jira (2026-10-19, user): every new report becomes a Jira issue on its own -- unless
 * the switch is off -- and MIS can send one by hand, try a failed one again, or send every report not yet
 * there at once (2026-10-20, user: production had reports from before Jira). Status and kind then go both
 * ways: Jira's are read back on every webhook delivery and scheduled run, and MIS changing them in the
 * portal changes the issue first (2026-10-20, user). Fixed in is the portal's unless the issue names a fix
 * version -- IPS's screens carry none. The note, who handles it and the version level stay the portal's.
 *
 * Sending is one report at a time, behind a lock, in steps a retry can carry on from: find or create the
 * issue (its id is kept the moment Jira gives it; one deleted in Jira since is made anew), attach each
 * screenshot not yet there, then read the issue back -- which, for a report already there, is its sync. A failed step leaves the report failed with Jira's words; the scheduler tries again up to
 * five times while the switch is on, and a refused account does not count against the report.
 *
 * A report Jira no longer has is marked deleted -- unless every issue asked about at once came back
 * missing, which says the account lost sight of the project rather than that the issues went.
 *
 * Production and staging share one project (2026-10-21, user), so a server follows only the reports it was sent
 * on, whose issue -- if one is live -- is in the configured project (handles()): the Test Server's copies of
 * production's reports, and its old links into the sandbox it used before, are left as they are. A retry searches
 * for an issue only after this server really started making one, and a new issue is first put in step with its
 * report -- moved to its column, a duplicate labelled and linked -- before a read-back may take anything from it.
 *
 * A report's plan is the issue's too (2026-10-22, user): its start and due dates, the epic it sits under and the team
 * that owns it go both ways as kind and status do. A new issue carries what its create screen takes of them -- and the
 * default team (DEV) when the report names none -- and a change in the portal edits only what Jira holds otherwise.
 *
 * A project's key can change (2026-10-22, user: IPS became IP2, and IPS-1 to IPS-3 were followed by IP2-4). Jira
 * still takes the old key, so an issue is this project's under the configured key or the one Jira gives it now,
 * and a report linked under a key the project no longer goes by is found again by its id when issues are read back.
 *
 * An archived or deleted report (2026-10-21, user) is out of Jira's way: never queued, sent or read back until it
 * is back. Removing an open linked one moves its issue to Cancelled first (cancelForRemoval), under the same lock
 * a send takes, so the two never cross.
 */
final class PortalBugReportsJira
{
    public const STATE_PENDING = 'pending';

    public const STATE_SENT = 'sent';

    public const STATE_FAILED = 'failed';

    public const STATE_DELETED = 'deleted';

    public const MAX_ATTEMPTS = 5;

    // Send to Jira: sending, the switch, and changing a linked report's kind or status.
    public const RULE = 'bug-reports.jira';

    // A run's share: enough to clear a busy hour, few enough to finish inside the minute the scheduler allows.
    private const BATCH = 25;

    private const SETTING = 'jira.auto_send';

    private const FALLBACK_TYPE = 'Task';

    private const DELETED_MESSAGE = 'Deleted in Jira, or the Jira account can no longer see it.';

    public function __construct(
        private readonly PortalJiraClient $jira,
        private readonly PortalCloudinary $cloudinary,
        private readonly PortalJiraEnvironment $environment,
        private readonly PortalJiraPeople $people,
    ) {}

    public function isConfigured(): bool
    {
        return $this->jira->isConfigured();
    }

    // The channel a new report is recorded as sent on.
    public function channel(): string
    {
        return $this->environment->channel();
    }

    // This server follows the report's Jira side: it was sent here, and any live issue is in this project.
    public function handles(object $row): bool
    {
        return $this->whyNotHandled($row) === null;
    }

    // Why this server leaves a report's Jira side alone, or null when it does not.
    public function whyNotHandled(object $row): ?string
    {
        if (! $this->environment->ownsRow($row)) {
            return 'It came here with another server\'s data, so that server sends it to Jira and follows its issue.';
        }
        $key = $row->jira_issue_key ?? null;
        $project = $this->jira->projectKey();
        // Jira is asked which keys the project goes by only for an issue whose key is not the configured one's.
        if ($key === null || $row->jira_state === self::STATE_DELETED || $project === null
            || self::isKeyOf((string) $key, [$project]) || self::isKeyOf((string) $key, $this->jira->projectKeys())) {
            return null;
        }

        return $key.' is in another Jira project than '.$project.', so this server leaves it as it is.';
    }

    // Whether new reports go to Jira on their own; on until someone turns it off.
    public function autoSend(): bool
    {
        $value = DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', self::SETTING)
            ->value('setting_value');

        return $value === null || in_array(strtolower(trim((string) $value)), ['1', 'true'], true);
    }

    public function setAutoSend(bool $on): void
    {
        DB::connection('core')->table('settings')->updateOrInsert(
            ['product' => 'portal', 'setting_key' => self::SETTING],
            ['setting_value' => $on ? '1' : '0'],
        );
    }

    public function shouldSendNew(): bool
    {
        return $this->jira->isConfigured() && $this->autoSend();
    }

    /**
     * What the page shows about Jira as a whole.
     *
     * @return array{connection: string, connectionMessage: ?string, checkedAt: ?string, autoSend: bool, projectKey: ?string, environment: ?string}
     */
    public function settings(): array
    {
        $connection = $this->jira->connection();

        return [
            'connection' => $connection['state'],
            'connectionMessage' => $connection['message'],
            'checkedAt' => $connection['checkedAt'],
            'autoSend' => $this->autoSend(),
            'projectKey' => $this->jira->projectKey(),
            // The label this server adds in the shared project, or null on production.
            'environment' => $this->environment->label(),
        ];
    }

    // Jira owns the report: an issue it still has, which this server follows.
    public function owns(object $row): bool
    {
        return $row->jira_issue_id !== null && $row->jira_state !== self::STATE_DELETED && $this->handles($row);
    }

    /**
     * A report's Jira link as the page shows it, or null when it was never sent.
     *
     * @return array{state: string, key: ?string, url: ?string, status: ?string, error: ?string, syncedAt: ?string}|null
     */
    public function present(object $row): ?array
    {
        if ($row->jira_state === null) {
            return null;
        }
        $key = $row->jira_issue_key === null ? null : (string) $row->jira_issue_key;

        return [
            'state' => (string) $row->jira_state,
            'key' => $key,
            'url' => $key === null ? null : $this->jira->browseUrl($key),
            'status' => $row->jira_status,
            'error' => $row->jira_error,
            'syncedAt' => $row->jira_synced_at === null ? null : str_replace(' ', 'T', substr((string) $row->jira_synced_at, 0, 19)).'Z',
        ];
    }

    /**
     * Send one report as far as it will go, and say where it got to; false when another send holds it.
     */
    public function push(int $id): bool
    {
        return $this->withReportLock($id, function () use ($id): void {
            $row = $this->row($id);
            if ($row !== null && self::isTracked($row) && $this->handles($row)) {
                $this->sendSteps($row);
            }
        });
    }

    /**
     * Run $work while holding the report against every send, and keep one off meanwhile; false when a send
     * holds it now.
     *
     * @param  Closure(): void  $work
     */
    public function withReportLock(int $id, Closure $work): bool
    {
        $lock = Cache::lock('portal-jira-push:'.$id, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            $work();
        } finally {
            $lock->release();
        }

        return true;
    }

    // The reports still followed in Jira: neither archived nor deleted.
    public static function tracked(Builder $query): Builder
    {
        return $query->whereNull('archived_at')->whereNull('deleted_at');
    }

    public static function isTracked(object $row): bool
    {
        return ($row->archived_at ?? null) === null && ($row->deleted_at ?? null) === null;
    }

    /**
     * Before an open linked report is archived or deleted (2026-10-21, user): its issue moved to Cancelled, and the
     * report with it, as won't fix. Jira is asked first, and moves nothing for an issue it no longer has -- marked
     * deleted -- or one already closed there, which is read back. The key of an issue this moved, else null.
     */
    public function cancelForRemoval(object $row): ?string
    {
        $issue = $this->jira->issue((string) $row->jira_issue_id);
        if ($issue === null) {
            $this->markDeleted((int) $row->id);

            return null;
        }
        $closed = [PortalBugReports::STATUS_FIXED, PortalBugReports::STATUS_CANCELLED];
        if (in_array(PortalJiraIssue::portalStatus($issue['fields'], (string) $row->status), $closed, true)) {
            $this->apply($row, $issue);

            return null;
        }
        $changes = ['status' => PortalBugReports::STATUS_CANCELLED, 'cancel_reason' => PortalBugReports::CANCEL_WONT_FIX, 'duplicate_of' => null];
        $this->changeInJira($row, $changes);
        DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update([...$changes, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);

        return (string) ($this->row((int) $row->id)?->jira_issue_key ?? $row->jira_issue_key);
    }

    /**
     * Every report never sent, or failed, put on its way to Jira with its tries started over: the ids queued.
     *
     * @return list<int>
     */
    public function queueUnsent(): array
    {
        $ids = $this->handled(DB::connection('portal')->table('bug_reports'))
            ->where(fn ($query) => $query->whereNull('jira_state')->orWhere('jira_state', self::STATE_FAILED))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($ids !== []) {
            DB::connection('portal')->table('bug_reports')->whereIn('id', $ids)
                ->update(['jira_state' => self::STATE_PENDING, 'jira_attempts' => 0, 'jira_error' => null]);
        }

        return $ids;
    }

    /**
     * A linked report's new kind or status, made in Jira before the portal keeps it: the work type edited, or
     * the issue moved along the transition landing in the matching column -- and, for triage (2026-10-21, user), a
     * duplicate labelled and linked to its original's issue, or no longer. Throws Jira's own words.
     *
     * @param  array<string, mixed>  $changes
     */
    public function changeInJira(object $row, array $changes): void
    {
        $issueId = (string) $row->jira_issue_id;
        $status = $changes['status'] ?? (string) $row->status;
        $reason = array_key_exists('cancel_reason', $changes) ? $changes['cancel_reason'] : $row->cancel_reason;
        $original = array_key_exists('duplicate_of', $changes) ? $changes['duplicate_of'] : $row->duplicate_of;
        $wasDuplicate = $row->status === PortalBugReports::STATUS_CANCELLED && $row->cancel_reason === PortalBugReports::CANCEL_DUPLICATE;
        $isDuplicate = $status === PortalBugReports::STATUS_CANCELLED && $reason === PortalBugReports::CANCEL_DUPLICATE;
        $oldTarget = $wasDuplicate ? $this->duplicateTarget($row->duplicate_of) : null;
        $newTarget = $isDuplicate ? $this->duplicateTarget($original) : null;

        // Reads first, so a move Jira has no way to make changes nothing.
        $move = isset($changes['status']) && $changes['status'] !== $row->status
            ? $this->findMove($issueId, (string) $changes['status'], (string) $row->jira_issue_key)
            : null;
        $plan = self::planChanges($row, $changes);
        $unlinks = $oldTarget !== null && $oldTarget !== $newTarget;
        $current = $unlinks || $plan !== [] ? ($this->jira->issue($issueId)['fields'] ?? []) : [];
        $staleLinks = $unlinks ? PortalJiraIssue::duplicateLinkIds($current, $this->duplicateLinkType(), $oldTarget) : [];
        $planEdit = $plan === [] ? [] : $this->planEdit($plan, $current);

        if (isset($changes['kind']) && $changes['kind'] !== $row->kind) {
            $this->jira->editIssue($issueId, ['issuetype' => ['id' => $this->issueTypeId((string) $changes['kind'])]]);
        }
        // The plan after the kind, whose work type decides the screen the fields are edited on.
        if ($planEdit !== []) {
            $this->editPlan($issueId, $planEdit);
        }
        // Triage (2026-10-21, user): the label and the link before the move, so a delivery racing the save already
        // sees a cancelled duplicate as one.
        foreach ($staleLinks as $linkId) {
            $this->jira->deleteIssueLink($linkId);
        }
        if ($wasDuplicate && ! $isDuplicate) {
            $this->jira->changeLabels($issueId, [], [$this->duplicateLabel()]);
        }
        if ($isDuplicate && ! $wasDuplicate) {
            $this->jira->changeLabels($issueId, [$this->duplicateLabel()]);
        }
        if ($newTarget !== null && $newTarget !== $oldTarget) {
            $this->jira->linkIssues($this->duplicateLinkType(), $issueId, $newTarget);
        }
        if ($move !== null) {
            $this->jira->transition($issueId, $move['id']);
        }
        $issue = $this->jira->issue($issueId);
        if ($issue !== null) {
            DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update([
                'jira_issue_key' => $issue['key'],
                'jira_status' => PortalJiraIssue::statusName($issue['fields']),
                'jira_synced_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * The move that lands the issue in the column a portal status stands for, from where it is now.
     *
     * @return array{id: string, to: array<string, mixed>}
     */
    private function findMove(string $issueId, string $wanted, string $issueKey): array
    {
        foreach ($this->jira->transitions($issueId) as $candidate) {
            if (PortalJiraIssue::portalStatus(['status' => $candidate['to']], '') === $wanted) {
                return $candidate;
            }
        }

        throw new RuntimeException('Jira has no way to move '.$issueKey.' to '.PortalBugReports::STATUS_LABELS[$wanted].' from where it is.', 409);
    }

    // Read one issue back, as a webhook delivery asks; a report Jira no longer has is marked deleted.
    public function syncIssue(string $issueId): void
    {
        $row = self::tracked($this->environment->scope(DB::connection('portal')->table('bug_reports')))->where('jira_issue_id', $issueId)->first();
        if ($row === null || ! $this->owns($row)) {
            return;
        }
        $issue = $this->jira->issue($issueId);
        if ($issue === null) {
            $this->markDeleted((int) $row->id);

            return;
        }
        $this->apply($row, $issue);
    }

    /**
     * Read every linked report of the configured project back, a hundred at a time, then find again by id any
     * linked under a key the project no longer goes by.
     *
     * @return array{synced: int, deleted: int}
     */
    public function reconcile(): array
    {
        $synced = 0;
        $deleted = 0;
        $keys = $this->linkedProjectKeys();
        if ($keys === []) {
            return ['synced' => 0, 'deleted' => 0];
        }
        $refused = false;
        self::tracked($this->environment->scope(DB::connection('portal')->table('bug_reports')))
            ->whereNotNull('jira_issue_id')
            ->where('jira_state', '!=', self::STATE_DELETED)
            ->where(function (Builder $inner) use ($keys): void {
                foreach ($keys as $key) {
                    $inner->orWhere('jira_issue_key', 'like', self::keyPattern($key));
                }
            })
            ->chunkById(100, function ($rows) use (&$synced, &$deleted, &$refused): ?bool {
                $byIssue = $rows->keyBy('jira_issue_id');
                $fetched = $this->jira->bulkFetch(array_map('strval', $byIssue->keys()->all()));
                foreach ($fetched['issues'] as $issue) {
                    if ($byIssue->has($issue['id'])) {
                        $this->apply($byIssue[$issue['id']], $issue);
                        $synced++;
                    }
                }
                if ($fetched['issues'] === [] && count($fetched['missing']) >= 2) {
                    Log::warning('portal.jira.all_missing', ['asked' => count($fetched['missing'])]);
                    $this->jira->refuse('Jira returned none of the '.count($fetched['missing']).' issues asked about: check the account can still browse the project.');
                    $refused = true;

                    return false;
                }
                foreach ($fetched['missing'] as $issueId) {
                    $this->markDeleted((int) $byIssue[$issueId]->id);
                    $deleted++;
                }

                return null;
            });
        if (! $refused) {
            $synced += $this->rekey($keys);
        }

        return ['synced' => $synced, 'deleted' => $deleted];
    }

    /**
     * The reports linked under a key the project no longer goes by -- its key changed, and the configuration moved
     * on with it -- asked for by id: Jira answers with each issue's key now, which apply() keeps when it is this
     * project's. An issue in another project, or one Jira does not show, is left as it is.
     *
     * @param  list<string>  $keys
     */
    private function rekey(array $keys): int
    {
        $found = 0;
        $query = self::tracked($this->environment->scope(DB::connection('portal')->table('bug_reports')))
            ->whereNotNull('jira_issue_id')
            ->whereNotNull('jira_issue_key')
            ->where('jira_state', '!=', self::STATE_DELETED);
        foreach ($keys as $key) {
            $query->where('jira_issue_key', 'not like', self::keyPattern($key));
        }
        $query->chunkById(100, function ($rows) use ($keys, &$found): void {
            $byIssue = $rows->keyBy('jira_issue_id');
            foreach ($this->jira->bulkFetch(array_map('strval', $byIssue->keys()->all()))['issues'] as $issue) {
                if ($byIssue->has($issue['id']) && self::isKeyOf($issue['key'], $keys)) {
                    $this->apply($byIssue[$issue['id']], $issue);
                    $found++;
                }
            }
        });

        return $found;
    }

    /**
     * The scheduler's run: send what is waiting while the switch is on, then read every linked issue back.
     * A refused or unreachable Jira ends the sending, since every other report would fail the same way.
     *
     * @return array{sent: int, failed: int, synced: int, deleted: int}
     */
    public function run(): array
    {
        $sent = 0;
        $failed = 0;
        if ($this->autoSend()) {
            $waiting = $this->handled(DB::connection('portal')->table('bug_reports'))
                ->whereIn('jira_state', [self::STATE_PENDING, self::STATE_FAILED])
                ->where('jira_attempts', '<', self::MAX_ATTEMPTS)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');
            foreach ($waiting as $id) {
                $this->push((int) $id);
                if ($this->row((int) $id)?->jira_state === self::STATE_SENT) {
                    $sent++;
                } else {
                    $failed++;
                }
                if (in_array($this->jira->connection()['state'], [PortalJiraClient::REFUSED, PortalJiraClient::UNREACHABLE], true)) {
                    break;
                }
            }
        }
        $read = $this->reconcile();

        return ['sent' => $sent, 'failed' => $failed, 'synced' => $read['synced'], 'deleted' => $read['deleted']];
    }

    private function sendSteps(object $row): void
    {
        $id = (int) $row->id;
        try {
            // An issue made before and deleted in Jira since is made anew rather than attached to in vain.
            if ($row->jira_issue_id !== null && $row->jira_state !== self::STATE_DELETED && $this->jira->issue((string) $row->jira_issue_id) === null) {
                $this->markDeleted($id);
                $row = $this->row($id) ?? $row;
            }
            if ($row->jira_issue_id === null || $row->jira_state === self::STATE_DELETED) {
                $row = $this->linkIssue($row);
            }
            if ($row->jira_create_started_at !== null) {
                $this->putInStep($row);
                DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['jira_create_started_at' => null]);
                $row = $this->row($id) ?? $row;
            }
            $images = DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $id)->orderBy('id')->get()->values();
            for ($index = (int) $row->jira_attached; $index < $images->count(); $index++) {
                $image = $images[$index];
                try {
                    $this->jira->attach(
                        (string) $row->jira_issue_id,
                        PortalJiraIssue::attachmentName($index + 1, (string) $image->mime_type),
                        $this->cloudinary->download((string) $image->file_url),
                        (string) $image->mime_type,
                    );
                } catch (RuntimeException $error) {
                    throw new RuntimeException('Screenshot '.($index + 1).' could not be attached: '.$error->getMessage(), $error->getCode());
                }
                DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['jira_attached' => $index + 1]);
            }
            $issue = $this->jira->issue((string) $row->jira_issue_id);
            if ($issue === null) {
                $this->markDeleted($id);

                return;
            }
            $this->apply($this->row($id) ?? $row, $issue);
            DB::connection('portal')->table('bug_reports')->where('id', $id)->update([
                'jira_state' => self::STATE_SENT,
                'jira_error' => null,
                'jira_attempts' => 0,
            ]);
        } catch (RuntimeException $error) {
            $refused = in_array($error->getCode(), [401, 403], true);
            if ($error->getCode() === 400 && str_contains($error->getMessage(), 'issuetype')) {
                $this->jira->forgetIssueTypes();
            }
            DB::connection('portal')->table('bug_reports')->where('id', $id)->update([
                'jira_state' => self::STATE_FAILED,
                'jira_error' => mb_substr($error->getMessage(), 0, 255),
                'jira_attempts' => $refused ? DB::raw('jira_attempts') : DB::raw('LEAST(jira_attempts + 1, 255)'),
            ]);
            Log::warning('portal.jira.send_failed', ['report' => $id, 'status' => $error->getCode()]);
        }
    }

    // The issue the report becomes: one an earlier, unanswered try of this server's already made, or a new one.
    private function linkIssue(object $row): object
    {
        $environment = $this->environment->label();
        $issue = null;
        // Only a create this server really started can have made an issue, and only since the day before it.
        $started = $row->jira_create_started_at ?? null;
        if ($started !== null) {
            $since = date('Y-m-d', strtotime(substr((string) $started, 0, 10).' -1 day') ?: time());
            foreach ($this->jira->issuesDescribing('report '.$row->id, $this->environment->searchJql(), $since) as $found) {
                if (PortalJiraIssue::isForReport($found['fields'], (int) $row->id, $environment)) {
                    $issue = $found;
                    break;
                }
            }
        }
        $default = null;
        if ($issue === null) {
            $kind = array_key_exists((string) $row->kind, PortalBugReports::KINDS) ? (string) $row->kind : PortalBugReports::KIND_BUG;
            // Every read first -- the type, and the plan its screen takes -- so a refused one makes nothing, and
            // there is nothing for a retry to look for.
            $typeId = $this->issueTypeId($kind);
            [$plan, $default] = $this->planForCreate($row, $typeId);
            if ($started === null) {
                DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update(['jira_create_started_at' => now('UTC')->format('Y-m-d H:i:s')]);
            }
            $issue = $this->jira->createIssue(PortalJiraIssue::fields(
                $row, (string) $this->jira->projectKey(), $typeId, (string) config('services.jira.label', 'ikaika-portal'), $this->senderName($row), $environment, $plan,
            ));
        }
        DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update([
            'jira_issue_id' => $issue['id'],
            'jira_issue_key' => $issue['key'],
            'jira_state' => self::STATE_PENDING,
            'jira_status' => null,
            'jira_attached' => 0,
            // Filed under the default team, the report names it too.
            ...($default === null ? [] : ['team_id' => $default['id'], 'team_name' => $default['name']]),
        ]);

        return $this->row((int) $row->id) ?? $row;
    }

    /**
     * The issue type a kind is filed as: the one named for it, else Task, else none the project has.
     *
     * @return array{wanted: string, id: ?string, name: ?string}
     */
    public function issueTypeFor(string $kind): array
    {
        $types = $this->jira->issueTypes();
        $wanted = trim((string) config('services.jira.issue_types.'.$kind, self::FALLBACK_TYPE));
        foreach ([$wanted, self::FALLBACK_TYPE] as $name) {
            foreach ($types as $type) {
                if (strcasecmp($type['name'], $name) === 0) {
                    return ['wanted' => $wanted, 'id' => $type['id'], 'name' => $type['name']];
                }
            }
        }

        return ['wanted' => $wanted, 'id' => null, 'name' => null];
    }

    /**
     * What a report's plan is picked from (2026-10-22, user): the teams and epics, the team a new issue goes to,
     * which plan fields the site has, and which MIS members Jira knows -- that last as best it can, since a
     * missing Browse users and groups must not hide the rest.
     *
     * @return array{teams: list<array{id: string, name: string}>, defaultTeamId: ?string, epics: list<array{key: string, summary: string, isDone: bool}>, fields: array{team: bool, startDate: bool}, people: list<array{id: string, inJira: bool}>}
     */
    public function planOptions(): array
    {
        $ids = $this->jira->fieldIds();
        try {
            $people = array_map(static fn (array $member): array => ['id' => (string) $member['id'], 'inJira' => $member['accountId'] !== null], $this->people->roster());
        } catch (RuntimeException) {
            $people = [];
        }

        return [
            'teams' => $this->jira->teams(),
            'defaultTeamId' => $this->defaultTeam()['id'] ?? null,
            'epics' => $this->jira->epics(),
            'fields' => ['team' => $ids['team'] !== null, 'startDate' => $ids['startDate'] !== null],
            'people' => $people,
        ];
    }

    /**
     * The project's epics, or its teams; fresh asks Jira again rather than taking what is kept.
     *
     * @return list<array<string, mixed>>
     */
    public function planList(string $which, bool $fresh = false): array
    {
        if ($which === 'epics') {
            if ($fresh) {
                $this->jira->forgetEpics();
            }

            return $this->jira->epics();
        }
        if ($fresh) {
            $this->jira->forgetTeams();
        }

        return $this->jira->teams();
    }

    public function forgetEpics(): void
    {
        $this->jira->forgetEpics();
    }

    // The key the project goes by now, which a refusal names it by.
    public function projectLabel(): string
    {
        $keys = $this->jira->projectKeys();

        return (string) (end($keys) ?: $this->jira->projectKey());
    }

    /**
     * The team a new issue is filed under (2026-10-22, user: DEV): the one JIRA_DEFAULT_TEAM names, when exactly
     * one of the field's teams has that name; none when it is blank or names none.
     *
     * @return array{id: string, name: string}|null
     */
    public function defaultTeam(): ?array
    {
        $name = trim((string) config('services.jira.default_team', ''));
        if ($name === '') {
            return null;
        }
        $named = array_values(array_filter($this->jira->teams(), static fn (array $team): bool => strcasecmp($team['name'], $name) === 0));

        return count($named) === 1 ? $named[0] : null;
    }

    private function issueTypeId(string $kind): string
    {
        $type = $this->issueTypeFor($kind);
        if ($type['id'] === null) {
            throw new RuntimeException('Jira has no issue type '.$type['wanted'].' or '.self::FALLBACK_TYPE.' in '.$this->jira->projectKey().'.');
        }

        return $type['id'];
    }

    /**
     * What Jira says now: its key and status name, and the portal status and fix version they stand for.
     *
     * @param  array{id: string, key: string, fields: array<string, mixed>}  $issue
     */
    private function apply(object $row, array $issue): void
    {
        // A new issue not yet in step with its report says nothing about the report yet.
        if (($row->jira_create_started_at ?? null) !== null) {
            DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update([
                'jira_issue_key' => $issue['key'],
                'jira_status' => PortalJiraIssue::statusName($issue['fields']),
                'jira_synced_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);

            return;
        }
        $status = PortalJiraIssue::portalStatus($issue['fields'], (string) $row->status);
        // The portal's own Fixed in stands until the issue names a fix version.
        $fixedIn = PortalJiraIssue::fixedIn($issue['fields']) ?? $row->fixed_in;
        $kind = $this->kindOfType($issue['fields']['issuetype']['name'] ?? null, (string) $row->kind);
        [$reason, $original] = $this->triageFrom($row, $issue, $status);
        $plan = PortalJiraIssue::planOf($issue['fields'], $this->jira->fieldIds());
        $changes = [
            'jira_issue_key' => $issue['key'],
            'jira_status' => PortalJiraIssue::statusName($issue['fields']),
            'jira_synced_at' => now('UTC')->format('Y-m-d H:i:s'),
        ];
        $was = ($row->duplicate_of ?? null) === null ? null : (int) $row->duplicate_of;
        $planMoved = self::planChanges($row, $plan) !== [] || ($plan['parent_summary'] ?? null) !== ($row->parent_summary ?? null)
            || (array_key_exists('team_name', $plan) && $plan['team_name'] !== null && $plan['team_name'] !== ($row->team_name ?? null));
        if ($status !== $row->status || $fixedIn !== $row->fixed_in || $kind !== $row->kind || $reason !== ($row->cancel_reason ?? null) || $original !== $was || $planMoved) {
            $changes += [
                'status' => $status, 'fixed_in' => $fixedIn, 'kind' => $kind, 'cancel_reason' => $reason, 'duplicate_of' => $original,
                // Jira's name for a team stands; a team it gave by id alone keeps the name the portal has.
                ...$plan, ...(array_key_exists('team_name', $plan) && $plan['team_name'] === null && ($plan['team_id'] ?? null) === ($row->team_id ?? null) ? ['team_name' => $row->team_name ?? null] : []),
                'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
            ];
            Log::info('portal.jira.synced', ['report' => (int) $row->id, 'status' => $status]);
        }
        DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update($changes);
    }

    /**
     * The plan columns a save or a read-back changes from what the report has (2026-10-22, user).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, ?string>
     */
    public static function planChanges(object $row, array $changes): array
    {
        $changed = [];
        foreach (['start_on', 'due_on', 'parent_key', 'team_id'] as $column) {
            if (array_key_exists($column, $changes) && self::planValue($column, $changes[$column]) !== self::planValue($column, $row->$column ?? null)) {
                $changed[$column] = self::planValue($column, $changes[$column]);
            }
        }

        return $changed;
    }

    // A plan column as it compares: a day as YYYY-MM-DD, whatever the database adds to it.
    private static function planValue(string $column, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_ends_with($column, '_on') ? substr((string) $value, 0, 10) : (string) $value;
    }

    /**
     * The edit a save makes to the issue's plan: each field it changes that Jira holds otherwise. A parent is
     * cleared only when the issue has one, since Jira answers the clearing of none with an error.
     *
     * @param  array<string, ?string>  $changed
     * @param  array<string, mixed>  $fields  the issue as Jira has it now
     * @return array<string, mixed>
     */
    private function planEdit(array $changed, array $fields): array
    {
        $ids = $this->jira->fieldIds();
        $held = PortalJiraIssue::planOf($fields, $ids);
        $edit = [];
        if (array_key_exists('due_on', $changed) && $changed['due_on'] !== ($held['due_on'] ?? null)) {
            $edit['duedate'] = $changed['due_on'];
        }
        if ($ids['startDate'] !== null && array_key_exists('start_on', $changed) && $changed['start_on'] !== ($held['start_on'] ?? null)) {
            $edit[$ids['startDate']] = $changed['start_on'];
        }
        if ($ids['team'] !== null && array_key_exists('team_id', $changed) && $changed['team_id'] !== ($held['team_id'] ?? null)) {
            $edit[$ids['team']] = $changed['team_id'];
        }
        if (array_key_exists('parent_key', $changed) && $changed['parent_key'] !== $held['parent_key']) {
            if ($changed['parent_key'] !== null) {
                $edit['parent'] = ['key' => $changed['parent_key']];
            } elseif ($held['parent_key'] !== null) {
                $edit['parent'] = null;
            }
        }

        return $edit;
    }

    /**
     * The plan edit sent, with Jira's words naming the fields as people know them; a refused team forgets the
     * teams kept, since one was renamed or removed.
     *
     * @param  array<string, mixed>  $edit
     */
    private function editPlan(string $issueId, array $edit): void
    {
        $ids = $this->jira->fieldIds();
        try {
            $this->jira->editIssue($issueId, $edit);
        } catch (RuntimeException $error) {
            if ($ids['team'] !== null && str_contains($error->getMessage(), $ids['team'])) {
                $this->jira->forgetTeams();
            }
            $names = array_filter([(string) $ids['team'] => 'Team', (string) $ids['startDate'] => 'Start date'], static fn (string $name, string $id): bool => $id !== '', ARRAY_FILTER_USE_BOTH);
            throw new RuntimeException(strtr($error->getMessage(), $names), $error->getCode());
        }
    }

    /**
     * What a new issue carries of its report's plan, in Jira's terms: the dates, the epic and the team -- the
     * report's, else the default -- as far as the type's create screen takes them; and the default team it went
     * to, which the report then names too. A screen Jira cannot say is taken to take them all.
     *
     * @return array{0: array<string, mixed>, 1: array{id: string, name: string}|null}
     */
    private function planForCreate(object $row, string $issueTypeId): array
    {
        $ids = $this->jira->fieldIds();
        $default = null;
        $team = $row->team_id ?? null;
        if ($team === null && $ids['team'] !== null) {
            try {
                $default = $this->defaultTeam();
            } catch (RuntimeException) {
                // A new issue is not held back for want of its team.
            }
            $team = $default['id'] ?? null;
        }
        $plan = array_filter([
            'parent' => ($row->parent_key ?? null) === null ? null : ['key' => (string) $row->parent_key],
            'duedate' => self::planValue('due_on', $row->due_on ?? null),
            ...($ids['startDate'] === null ? [] : [$ids['startDate'] => self::planValue('start_on', $row->start_on ?? null)]),
            ...($ids['team'] === null ? [] : [$ids['team'] => $team]),
        ], static fn (mixed $value): bool => $value !== null);
        $screen = $plan === [] ? null : $this->jira->createFields($issueTypeId);
        if ($screen !== null) {
            $plan = array_intersect_key($plan, array_flip($screen));
        }

        return [$plan, $default !== null && $ids['team'] !== null && isset($plan[$ids['team']]) ? $default : null];
    }

    /**
     * The kind an issue's work type stands for: the one filed as it, else the current one. Task is Feedback's,
     * and a kind whose own type the project lacks is filed as Task too, so that one stays as it was.
     */
    private function kindOfType(mixed $typeName, string $current): string
    {
        if (! is_string($typeName) || trim($typeName) === '') {
            return $current;
        }
        $wanted = fn (string $kind): string => trim((string) config('services.jira.issue_types.'.$kind, self::FALLBACK_TYPE));
        if (strcasecmp($wanted($current), $typeName) === 0) {
            return $current;
        }
        foreach (array_keys(PortalBugReports::KINDS) as $kind) {
            if (strcasecmp($wanted($kind), $typeName) !== 0) {
                continue;
            }
            if (strcasecmp($typeName, self::FALLBACK_TYPE) === 0) {
                try {
                    if (strcasecmp((string) $this->issueTypeFor($current)['name'], $typeName) === 0) {
                        return $current;
                    }
                } catch (RuntimeException) {
                    return $current;
                }
            }

            return $kind;
        }

        return $current;
    }

    // The name of a sender who chose to be named, for the issue's description; null for an anonymous one.
    private function senderName(object $row): ?string
    {
        $employee = ($row->reporter_id ?? null) === null ? null : Employee::query()->find((int) $row->reporter_id, ['id', 'first_name', 'last_name']);

        return $employee === null ? null : PortalSubmittedReportPresenter::memberName($employee->first_name, $employee->last_name);
    }

    /**
     * A new issue starts where its report already is: a report moved on before it reached Jira -- a closed one
     * send-all carries there, say -- lands in its own column rather than being reset to New by the read-back.
     */
    private function putInStep(object $row): void
    {
        if ((string) $row->status === PortalBugReports::STATUS_NEW) {
            return;
        }
        $issueId = (string) $row->jira_issue_id;
        $issue = $this->jira->issue($issueId);
        if ($issue === null) {
            return;
        }
        $move = PortalJiraIssue::portalStatus($issue['fields'], '') === (string) $row->status
            ? null
            : $this->findMove($issueId, (string) $row->status, (string) $row->jira_issue_key);
        // A duplicate already, before it reached Jira (2026-10-21): labelled and linked there as triage does.
        if ($row->status === PortalBugReports::STATUS_CANCELLED && ($row->cancel_reason ?? null) === PortalBugReports::CANCEL_DUPLICATE) {
            $this->jira->changeLabels($issueId, [$this->duplicateLabel()]);
            $target = $this->duplicateTarget($row->duplicate_of ?? null);
            if ($target !== null) {
                $this->jira->linkIssues($this->duplicateLinkType(), $issueId, $target);
            }
        }
        if ($move !== null) {
            $this->jira->transition($issueId, $move['id']);
        }
    }

    // The issue a duplicate's original became, when this server follows it; none to link to otherwise.
    private function duplicateTarget(mixed $originalId): ?string
    {
        if ($originalId === null) {
            return null;
        }
        $original = $this->row((int) $originalId);

        return $original !== null && $this->owns($original) ? (string) $original->jira_issue_id : null;
    }

    /**
     * A cancelled one's reason and original as the issue now stands: the portal's own while Jira says Cancelled,
     * worked out from the issue when Jira cancelled it on its own, and none once Jira moved it on -- the label and
     * the link then stay in Jira, for whoever moved it to tidy.
     *
     * @param  array{id: string, key: string, fields: array<string, mixed>}  $issue
     * @return array{0: ?string, 1: ?int}
     */
    private function triageFrom(object $row, array $issue, string $status): array
    {
        if ($status !== PortalBugReports::STATUS_CANCELLED) {
            return [null, null];
        }
        $reason = $row->cancel_reason ?? null;
        if ($row->status === PortalBugReports::STATUS_CANCELLED && $reason !== null) {
            return [(string) $reason, $reason === PortalBugReports::CANCEL_DUPLICATE && ($row->duplicate_of ?? null) !== null ? (int) $row->duplicate_of : null];
        }
        $reason = PortalJiraIssue::cancelReason($issue['fields'], $this->duplicateLabel());
        if ($reason !== PortalBugReports::CANCEL_DUPLICATE) {
            return [$reason, null];
        }
        $duplicated = PortalJiraIssue::duplicatedIssueId($issue['fields'], $this->duplicateLinkType());
        $found = $duplicated === null ? null : $this->environment->scope(DB::connection('portal')->table('bug_reports'))
            ->where('jira_issue_id', $duplicated)
            ->where('id', '!=', $row->id)
            ->first(['id', 'status', 'cancel_reason']);
        $isOriginal = $found !== null && ! ($found->status === PortalBugReports::STATUS_CANCELLED && $found->cancel_reason === PortalBugReports::CANCEL_DUPLICATE);

        return [PortalBugReports::CANCEL_DUPLICATE, $isOriginal ? (int) $found->id : null];
    }

    private function duplicateLabel(): string
    {
        return (string) config('services.jira.duplicate_label', 'duplicate');
    }

    private function duplicateLinkType(): string
    {
        return (string) config('services.jira.duplicate_link_type', 'Duplicate');
    }

    // The reports whose Jira side this server follows, as handles() says, for the queries that send.
    private function handled(Builder $query): Builder
    {
        $keys = $this->linkedProjectKeys();

        return self::tracked($this->environment->scope($query))->where(function (Builder $inner) use ($keys): void {
            $inner->whereNull('jira_issue_key')->orWhere('jira_state', self::STATE_DELETED);
            foreach ($keys as $key) {
                $inner->orWhere('jira_issue_key', 'like', self::keyPattern($key));
            }
        });
    }

    /**
     * The keys a linked report's issue may carry here: the configured one, and -- only once a report this server
     * sent is linked under another, as a changed project key leaves them -- the ones Jira says the project goes by.
     *
     * @return list<string>
     */
    private function linkedProjectKeys(): array
    {
        $configured = $this->jira->projectKey();
        if ($configured === null) {
            return [];
        }
        $other = self::tracked($this->environment->scope(DB::connection('portal')->table('bug_reports')))
            ->whereNotNull('jira_issue_key')
            ->where('jira_state', '!=', self::STATE_DELETED)
            ->where('jira_issue_key', 'not like', self::keyPattern($configured))
            ->exists();

        return $other ? $this->jira->projectKeys() : [$configured];
    }

    /**
     * Whether an issue's key is one of these projects': IP2-4 is IP2's, and not IP's.
     *
     * @param  list<string>  $projectKeys
     */
    private static function isKeyOf(string $issueKey, array $projectKeys): bool
    {
        foreach ($projectKeys as $projectKey) {
            if (str_starts_with(strtoupper($issueKey), $projectKey.'-')) {
                return true;
            }
        }

        return false;
    }

    // A LIKE pattern for one project's issue keys, its key's own % and _ escaped.
    private static function keyPattern(string $projectKey): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $projectKey).'-%';
    }

    private function markDeleted(int $id): void
    {
        DB::connection('portal')->table('bug_reports')->where('id', $id)->update([
            'jira_state' => self::STATE_DELETED,
            'jira_error' => self::DELETED_MESSAGE,
            'jira_synced_at' => now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    private function row(int $id): ?object
    {
        return DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
    }
}
