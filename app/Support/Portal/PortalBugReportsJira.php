<?php

namespace App\Support\Portal;

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
 * issue (its id is kept the moment Jira gives it), attach each screenshot not yet there, then read the
 * issue back. A failed step leaves the report failed with Jira's words; the scheduler tries again up to
 * five times while the switch is on, and a refused account does not count against the report.
 *
 * A report Jira no longer has is marked deleted -- unless every issue asked about at once came back
 * missing, which says the account lost sight of the project rather than that the issues went. Only issues
 * in the configured project are read back, so a copy of production's reports on the Test Server, which
 * names a sandbox project, is left alone.
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
    ) {}

    public function isConfigured(): bool
    {
        return $this->jira->isConfigured();
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
     * @return array{connection: string, connectionMessage: ?string, checkedAt: ?string, autoSend: bool, projectKey: ?string}
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
        ];
    }

    // Jira owns the report: an issue it still has.
    public function owns(object $row): bool
    {
        return $row->jira_issue_id !== null && $row->jira_state !== self::STATE_DELETED;
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

    // Every screenshot is in Jira and the issue is there: nothing left to send.
    public function isFullySent(object $row): bool
    {
        return $row->jira_state === self::STATE_SENT
            && (int) $row->jira_attached >= DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $row->id)->count();
    }

    /**
     * Send one report as far as it will go, and say where it got to; false when another send holds it.
     */
    public function push(int $id): bool
    {
        $lock = Cache::lock('portal-jira-push:'.$id, 120);
        if (! $lock->get()) {
            return false;
        }
        try {
            $row = $this->row($id);
            if ($row !== null) {
                $this->sendSteps($row);
            }
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * Every report never sent, or failed, put on its way to Jira with its tries started over: the ids queued.
     *
     * @return list<int>
     */
    public function queueUnsent(): array
    {
        $ids = DB::connection('portal')->table('bug_reports')
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
     * the issue moved along the transition landing in the matching column. Throws Jira's own words.
     *
     * @param  array<string, mixed>  $changes
     */
    public function changeInJira(object $row, array $changes): void
    {
        $issueId = (string) $row->jira_issue_id;
        if (isset($changes['kind']) && $changes['kind'] !== $row->kind) {
            $this->jira->editIssue($issueId, ['issuetype' => ['id' => $this->issueTypeId((string) $changes['kind'])]]);
        }
        if (isset($changes['status']) && $changes['status'] !== $row->status) {
            $wanted = (string) $changes['status'];
            $move = null;
            foreach ($this->jira->transitions($issueId) as $candidate) {
                if (PortalJiraIssue::portalStatus(['status' => $candidate['to']], '') === $wanted) {
                    $move = $candidate;
                    break;
                }
            }
            if ($move === null) {
                throw new RuntimeException('Jira has no way to move '.$row->jira_issue_key.' to '.PortalBugReports::STATUS_LABELS[$wanted].' from where it is.', 409);
            }
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

    // Read one issue back, as a webhook delivery asks; a report Jira no longer has is marked deleted.
    public function syncIssue(string $issueId): void
    {
        $row = DB::connection('portal')->table('bug_reports')->where('jira_issue_id', $issueId)->first();
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
     * Read every linked report of the configured project back, a hundred at a time.
     *
     * @return array{synced: int, deleted: int}
     */
    public function reconcile(): array
    {
        $synced = 0;
        $deleted = 0;
        $prefix = $this->jira->projectKey().'-';
        DB::connection('portal')->table('bug_reports')
            ->whereNotNull('jira_issue_id')
            ->where('jira_state', '!=', self::STATE_DELETED)
            ->where('jira_issue_key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%')
            ->chunkById(100, function ($rows) use (&$synced, &$deleted): ?bool {
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

                    return false;
                }
                foreach ($fetched['missing'] as $issueId) {
                    $this->markDeleted((int) $byIssue[$issueId]->id);
                    $deleted++;
                }

                return null;
            });

        return ['synced' => $synced, 'deleted' => $deleted];
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
            $waiting = DB::connection('portal')->table('bug_reports')
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
            if ($row->jira_issue_id === null || $row->jira_state === self::STATE_DELETED) {
                $row = $this->linkIssue($row);
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

    // The issue the report becomes: one an earlier, unanswered try already made, or a new one.
    private function linkIssue(object $row): object
    {
        $label = (string) config('services.jira.label', 'ikaika-portal');
        $issue = null;
        if ($row->jira_state !== null && $row->jira_state !== self::STATE_DELETED) {
            foreach ($this->jira->issuesDescribing('report '.$row->id, $label) as $found) {
                if (PortalJiraIssue::isForReport($found['fields'], (int) $row->id)) {
                    $issue = $found;
                    break;
                }
            }
        }
        if ($issue === null) {
            $kind = array_key_exists((string) $row->kind, PortalBugReports::KINDS) ? (string) $row->kind : PortalBugReports::KIND_BUG;
            $issue = $this->jira->createIssue(PortalJiraIssue::fields($row, (string) $this->jira->projectKey(), $this->issueTypeId($kind), $label));
        }
        DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update([
            'jira_issue_id' => $issue['id'],
            'jira_issue_key' => $issue['key'],
            'jira_state' => self::STATE_PENDING,
            'jira_status' => null,
            'jira_attached' => 0,
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
        $status = PortalJiraIssue::portalStatus($issue['fields'], (string) $row->status);
        // The portal's own Fixed in stands until the issue names a fix version.
        $fixedIn = PortalJiraIssue::fixedIn($issue['fields']) ?? $row->fixed_in;
        $kind = $this->kindOfType($issue['fields']['issuetype']['name'] ?? null, (string) $row->kind);
        $changes = [
            'jira_issue_key' => $issue['key'],
            'jira_status' => PortalJiraIssue::statusName($issue['fields']),
            'jira_synced_at' => now('UTC')->format('Y-m-d H:i:s'),
        ];
        if ($status !== $row->status || $fixedIn !== $row->fixed_in || $kind !== $row->kind) {
            $changes += ['status' => $status, 'fixed_in' => $fixedIn, 'kind' => $kind, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')];
            Log::info('portal.jira.synced', ['report' => (int) $row->id, 'status' => $status]);
        }
        DB::connection('portal')->table('bug_reports')->where('id', $row->id)->update($changes);
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
