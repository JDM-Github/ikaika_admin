<?php

namespace App\Support\Portal;

use App\Jobs\SendBugReportToJira;
use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Bug reports (2026-10-13, user): anyone signed in sends one from the button in the portal's corner,
 * with up to three screenshots, and MIS reads them on Bug Reports and tracks each to fixed.
 *
 * A report is anonymous unless its sender says otherwise (2026-10-20, user: testers asked to be named), and
 * that is enforced here rather than left to the page: submit() is handed the person only to keep their id
 * when they chose to be named -- a request that does not say stays anonymous, as every report before did --
 * writes no activity log, needs no location, and keeps only the day it came in.
 * A screenshot goes up under a neutral name and is served through a Cloudinary transformation, which
 * drops what a photo carries about the phone that took it. The only trace of the sender is the rate
 * limiter's hour-long counter, which never meets the report it counted.
 *
 * MIS hears of a new report on the bell (2026-10-13, user). An anonymous one's notice is from no one: it
 * names no actor, and it goes to everyone who holds Bug Reports -- the sender too, when they do -- so
 * nobody can tell who sent it by who was left out. A named one's says who.
 *
 * MIS's own changes -- status, internal note, who handles it, the semantic version level the fix needs
 * and the version it shipped in (2026-10-13) -- are theirs, and logged as anyone's are. Any MIS member
 * may be assigned, by anyone who holds the page or by taking it themselves. Nothing else about a report
 * changes while nobody handles it (2026-10-13, user): it is assigned first, or in the same save.
 *
 * Issues & Requests (2026-10-19, user): the button is used for feature requests and feedback as much as
 * for bugs, so a report has a kind the sender picks -- a bug when none is sent, as every report before
 * this was -- and MIS may file it under another. The page, notices and log name it by its kind.
 *
 * Each report becomes a Jira issue (PortalBugReportsJira). Once it is one, its kind and status are the
 * issue's too: a change here changes Jira first, and Jira's are read back -- and so is who handles it, the
 * issue's assignee (2026-10-22, user), which needs Send to Jira to change; up to ten can, the first Jira's
 * assignee and the others its watchers (PortalBugReportAssignees). Someone Jira has it assigned to from
 * outside MIS counts as handling it, though the page cannot assign them. A report records the server it was
 * sent on (2026-10-21, user: production and staging share one project), and only that server touches its issue.
 *
 * Triage (2026-10-21, user): Won't fix is shown as Cancelled -- the value stays wont_fix, which the apps from
 * before read strictly. A duplicate names the report it duplicates, which cannot be itself or a duplicate, so no
 * chain or loop forms; it is Fixed (2026-10-22, user: what it reports is handled under the original) and a
 * cancelled one is won't fix, but a duplicate cancelled before then stays as it is. Any other status clears both.
 * On a linked report the issue moves too, and a duplicate is labelled and linked there first. The columns keep
 * their names, cancel_reason and duplicate_of, as the API keeps cancelReason and duplicateOf.
 *
 * A report's plan (2026-10-22, user): when it starts and is due, the epic it sits under and the team that owns it --
 * the epic one of the project's and the team one of the site's, both checked with Jira. A linked report's are the
 * issue's too, changed in Jira first like its kind and status; an unlinked one's stay here until it is sent.
 *
 * Archive and delete (2026-10-21, user): an archived report is listed only when asked for, and stays as it was
 * until it is unarchived; a deleted one (PortalBugReportDeletion) is gone from the page into its deleter's
 * Recycle Bin. Either one moves an open linked report's issue to Cancelled first, and the report with it -- Jira
 * first, or nothing -- while a closed one's issue stays as it is.
 */
final class PortalBugReports
{
    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    // On staging, being tested (2026-10-20, user): the board's In Review, between the work and the release.
    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_FIXED = 'fixed';

    // Closed without a change, shown as Cancelled (2026-10-21, user); the value stays wont_fix.
    public const STATUS_CANCELLED = 'wont_fix';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_IN_REVIEW => 'In review',
        self::STATUS_FIXED => 'Fixed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    public const CANCEL_WONT_FIX = 'wont_fix';

    public const CANCEL_DUPLICATE = 'duplicate';

    /**
     * Why a closed one was closed, as the page names it: a cancelled one won't be fixed, a fixed one may be a duplicate.
     *
     * @var array<string, string>
     */
    public const CANCEL_REASONS = [
        self::CANCEL_WONT_FIX => "Won't fix",
        self::CANCEL_DUPLICATE => 'Duplicate',
    ];

    private const ONLY_FIXED_DUPLICATES = 'Only a fixed one can be a duplicate: set its status to Fixed too.';

    private const ONLY_CANCELLED_WONT_FIX = "Only a cancelled one is won't fix: set its status to Cancelled too.";

    // A duplicate, fixed as one since 2026-10-22 or cancelled as one before.
    public static function isDuplicate(mixed $status, mixed $reason): bool
    {
        return in_array($status, [self::STATUS_FIXED, self::STATUS_CANCELLED], true) && $reason === self::CANCEL_DUPLICATE;
    }

    public const KIND_BUG = 'bug';

    public const KIND_FEATURE = 'feature';

    public const KIND_FEEDBACK = 'feedback';

    /**
     * Each kind as the page labels it, as a sentence names one, as something is filed as it, the bell's
     * title for a new one, and what the bell says was done with it.
     *
     * @var array<string, array{label: string, noun: string, as: string, notice: string, verb: string}>
     */
    public const KINDS = [
        self::KIND_BUG => ['label' => 'Bug', 'noun' => 'bug report', 'as' => 'a bug report', 'notice' => 'New bug report', 'verb' => 'reported'],
        self::KIND_FEATURE => ['label' => 'Feature request', 'noun' => 'feature request', 'as' => 'a feature request', 'notice' => 'New feature request', 'verb' => 'requested'],
        self::KIND_FEEDBACK => ['label' => 'Feedback', 'noun' => 'feedback', 'as' => 'feedback', 'notice' => 'New feedback', 'verb' => 'shared'],
    ];

    /**
     * @var array<string, string>
     */
    public const VERSION_LEVELS = [
        'major' => 'Major',
        'minor' => 'Minor',
        'patch' => 'Patch',
    ];

    // Who may be assigned: the department that reads the reports.
    private const TEAM = 'mis';

    // The rule that opens Issues & Requests, and so who hears of a new one.
    private const RULE = 'bug-reports';

    private const SEMVER = '/^\d+\.\d+\.\d+$/';

    public const MAX_TITLE = 160;

    public const MAX_DESCRIPTION = 5000;

    public const MAX_NOTE = 2000;

    public const MAX_IMAGES = 3;

    private const MAX_PAGE = 200;

    // The portal's image picker refuses past this too.
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const FOLDER = 'ikaika-portal/bug-reports';

    public const BEING_SENT = 'It is being sent to Jira right now. Try again in a moment.';

    public function __construct(
        private readonly PortalCloudinary $cloudinary,
        private readonly PortalAudit $audit,
        private readonly PortalBugReportsJira $jira,
    ) {}

    /**
     * A new report. Deliberately takes no Employee: nothing here may learn who is sending it.
     *
     * @return array{section: string, resource: string, data: array{id: string}}
     */
    public function submit(Request $request, Employee $sender): array
    {
        $reporterId = $this->namedSender($request, $sender);
        $kind = $this->validatedKind($request->input('kind'), self::KIND_BUG);
        $title = $this->requiredText($request, 'title', self::MAX_TITLE, 'Give it a short title.', 'Keep the title under 160 characters.');
        $description = $this->requiredText($request, 'description', self::MAX_DESCRIPTION, 'Say what happened or what you need.', 'Keep the description under 5,000 characters.');
        $page = $this->optionalText($request, 'page', self::MAX_PAGE);
        $images = $this->validatedImages($request);
        // Said for what it is (2026-10-20, user): the shared upload's own refusal speaks of receipts.
        if ($images !== [] && ! $this->cloudinary->isConfigured()) {
            abort(503, 'Screenshots cannot be uploaded on this server yet: Cloudinary is not set up. Send it without them for now.');
        }

        // Uploaded before the row, so a failed upload adds nothing.
        $stored = [];
        foreach ($images as $index => $image) {
            $upload = $this->cloudinary->uploadReceipt($image, self::FOLDER, 'screenshot-'.($index + 1));
            $stored[] = [
                'file_url' => $this->withoutMetadata($upload['fileUrl']),
                'mime_type' => $upload['mimeType'],
                'size_bytes' => $upload['sizeBytes'],
            ];
        }

        // On its way to Jira as it comes in, unless MIS turned that off (2026-10-19).
        $toJira = $this->jira->shouldSendNew();
        $channel = $this->jira->channel();
        $id = DB::connection('portal')->transaction(function () use ($kind, $title, $description, $page, $stored, $toJira, $reporterId, $channel): int {
            $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
                'kind' => $kind,
                'jira_state' => $toJira ? PortalBugReportsJira::STATE_PENDING : null,
                'title' => $title,
                'description' => $description,
                'page' => $page,
                'status' => self::STATUS_NEW,
                'note' => null,
                'reporter_id' => $reporterId,
                // The server it was sent on: production and staging share one Jira project (2026-10-21, user).
                'channel' => $channel,
                // The day only: a time to the second could be lined up against what the sender did next.
                'reported_on' => now()->toDateString(),
                'updated_at' => null,
            ]);
            foreach ($stored as $image) {
                DB::connection('portal')->table('bug_report_images')->insert(['bug_report_id' => $id, ...$image]);
            }

            return $id;
        });

        $readers = [];
        foreach (PortalRules::holders([self::RULE]) as $holder) {
            if (PortalRole::normalize($holder['employee']->status) === 'active') {
                $readers[] = (int) $holder['employee']->getKey();
            }
        }
        $this->audit->notifyEach(
            $readers,
            PortalNotificationType::BUG_REPORTED,
            self::KINDS[$kind]['notice'],
            '"'.$title.'" was '.self::KINDS[$kind]['verb']
                .($reporterId === null ? '' : ' by '.($this->namesOf([$reporterId])[$reporterId] ?? 'a member'))
                .($page === null ? '' : ' on '.$page).'.',
            PortalShellPath::BUG_REPORTS,
            'Open Issues & Requests',
            ['recordId' => (string) $id],
            $reporterId,
        );
        if ($toJira) {
            Queue::push(new SendBugReportToJira($id));
            PortalQueueNow::afterResponse();
        }

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => ['id' => (string) $id]];
    }

    /**
     * Every report, newest first, with its screenshots and who handles it, and the MIS members it can be
     * given to. Nothing in a row says who sent it. Archived ones only when asked for -- an app from before
     * never asks, so it never meets one -- and deleted ones never.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, assignees: list<array{id: string, name: string}>, jira: array<string, mixed>}
     */
    public function list(bool $withArchived = false): array
    {
        $portal = DB::connection('portal');
        $rows = $portal->table('bug_reports')
            ->whereNull('deleted_at')
            ->when(! $withArchived, fn ($query) => $query->whereNull('archived_at'))
            ->orderByDesc('reported_on')
            ->orderByDesc('id')
            ->get();
        $images = $portal->table('bug_report_images')
            ->whereIn('bug_report_id', $rows->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('bug_report_id');
        $others = PortalBugReportAssignees::othersOf($rows->pluck('id')->all());
        foreach ($rows as $row) {
            $row->other_assignees = $others[(int) $row->id] ?? [];
        }
        $names = $this->namesOf([...$rows->pluck('assignee_id')->all(), ...array_merge([], ...array_values($others)), ...$rows->pluck('reporter_id')->all(), ...$rows->pluck('archived_by')->all()]);
        $titles = $rows->mapWithKeys(fn (object $row): array => [(int) $row->id => (string) $row->title])->all();
        $sync = $this->jira->syncFields();
        // An original out of the list, archived or deleted, still has its title.
        $away = array_diff($rows->pluck('duplicate_of')->filter()->map(fn ($id): int => (int) $id)->unique()->all(), array_keys($titles));
        if ($away !== []) {
            $titles += $portal->table('bug_reports')->whereIn('id', $away)->pluck('title', 'id')->mapWithKeys(fn ($title, $id): array => [(int) $id => (string) $title])->all();
        }

        return [
            'section' => 'bug-reports',
            'resource' => 'bug-reports',
            'data' => $rows->map(fn (object $row): array => $this->present($row, $images->get($row->id, collect())->all(), $names, $titles, $sync))->values()->all(),
            'assignees' => $this->assignees(),
            'jira' => $this->jira->settings(),
        ];
    }

    /**
     * MIS moves a report along: its kind, status, note, who handles it or its version. Only the fields sent change,
     * under the lock a send takes (2026-10-22), so a change for Jira and a send under way never cross.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, int $id, Request $request): array
    {
        $portal = DB::connection('portal');
        $row = null;
        $changes = [];
        $held = $this->jira->withReportLock($id, function () use ($actor, $id, $request, $portal, &$row, &$changes): void {
            $row = $this->changeableRow($id);
            $row->other_assignees = PortalBugReportAssignees::othersOf([$id])[$id] ?? [];
            $changes = $this->validatedChanges($row, $request);
            $handler = array_key_exists('assignee_id', $changes) ? $changes['assignee_id'] : $row->assignee_id;
            $others = $changes['other_assignees'] ?? $row->other_assignees;
            // Someone Jira has it assigned to from outside MIS handles it too (2026-10-22, user).
            $sync = $this->jira->syncFields();
            $inJira = $sync && ! array_key_exists('assignee_id', $changes) && ($row->jira_assignee_id ?? null) !== null && $this->jira->owns($row);
            if ($handler === null && $others === [] && ! $inJira && array_diff(array_keys($changes), ['assignee_id', 'other_assignees']) !== []) {
                abort(422, 'Assign someone to handle it before changing it.');
            }
            // A linked report's kind, status, plan and assignee are the issue's too (2026-10-20, 2026-10-22, user):
            // Jira changes first, or nothing does.
            $toJira = $this->jira->owns($row)
                && ((isset($changes['kind']) && $changes['kind'] !== $row->kind) || (isset($changes['status']) && $changes['status'] !== $row->status)
                    || array_key_exists('cancel_reason', $changes) || array_key_exists('duplicate_of', $changes)
                    || ($sync && (PortalBugReportsJira::planChanges($row, $changes) !== [] || PortalBugReportsJira::assigneeChanges($row, $changes)
                        || PortalBugReportsJira::othersChange($row, $changes))));
            $fromJira = [];
            if ($toJira) {
                if (! PortalRules::holds($actor, $request, PortalBugReportsJira::RULE)) {
                    abort(403, ($sync ? 'Its kind, status, assignee, team, parent and dates change ' : 'Its kind and status change ').$row->jira_issue_key.' in Jira too, which needs Send to Jira.');
                }
                if (! $this->jira->isConfigured()) {
                    abort(503, 'Jira is not connected yet.');
                }
                try {
                    $fromJira = $this->jira->changeInJira($row, $changes);
                } catch (RuntimeException $error) {
                    abort(422, 'Jira did not take the change: '.$error->getMessage());
                }
            }

            $portal->table('bug_reports')->where('id', $id)->update([...Arr::except($changes, ['other_assignees']), ...$fromJira, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);
            if (array_key_exists('other_assignees', $changes)) {
                PortalBugReportAssignees::setOthers($id, $changes['other_assignees']);
            }
        });
        if (! $held) {
            abort(409, self::BEING_SENT);
        }

        $saved = $portal->table('bug_reports')->where('id', $id)->first();
        $names = $this->namesOf([$saved->assignee_id, ...($changes['other_assignees'] ?? [])]);
        $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports', $this->logMessage($actor, $row, $changes, $names), (string) $id, $request, $changes);

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($saved)];
    }

    /**
     * The fields a save sends, as the columns they change; nothing sent is refused.
     *
     * @return array<string, mixed>
     */
    private function validatedChanges(object $row, Request $request): array
    {
        $changes = [];
        if ($request->has('kind')) {
            $changes['kind'] = $this->validatedKind($request->input('kind'), null);
        }
        if ($request->has('status')) {
            $status = (string) $request->input('status');
            if (! array_key_exists($status, self::STATUS_LABELS)) {
                abort(422, 'Choose New, In progress, In review, Fixed or Cancelled.');
            }
            $changes['status'] = $status;
        }
        if ($request->has('note')) {
            $changes['note'] = $this->optionalText($request, 'note', self::MAX_NOTE, 'Keep the note under 2,000 characters.');
        }
        // Several (2026-10-22, user), in order, the first Jira's assignee; an app from before sends just this one.
        if ($request->has('assigneeIds')) {
            $chosen = $this->validatedAssignees($request->input('assigneeIds'));
            $changes['assignee_id'] = $chosen[0] ?? null;
            $changes['other_assignees'] = array_slice($chosen, 1);
        } elseif ($request->has('assigneeId')) {
            $changes['assignee_id'] = $this->validatedAssignee($request->input('assigneeId'));
            $changes['other_assignees'] = [];
        }
        if (($changes['other_assignees'] ?? null) === $row->other_assignees) {
            unset($changes['other_assignees']);
        }
        if ($request->has('versionLevel')) {
            $level = trim((string) $request->input('versionLevel', ''));
            if ($level !== '' && ! array_key_exists($level, self::VERSION_LEVELS)) {
                abort(422, 'Choose Major, Minor or Patch.');
            }
            $changes['version_level'] = $level === '' ? null : $level;
        }
        if ($request->has('fixedIn')) {
            $version = trim((string) $request->input('fixedIn', ''));
            if ($version !== '' && preg_match(self::SEMVER, $version) !== 1) {
                abort(422, 'Write the version as X.Y.Z, like 1.4.2.');
            }
            $changes['fixed_in'] = $version === '' ? null : $version;
        }
        $triage = [];
        if ($request->has('cancelReason')) {
            $reason = trim((string) $request->input('cancelReason', ''));
            if ($reason !== '' && ! array_key_exists($reason, self::CANCEL_REASONS)) {
                abort(422, "Choose Won't fix or Duplicate.");
            }
            $triage['cancel_reason'] = $reason === '' ? null : $reason;
        }
        if ($request->has('duplicateOf')) {
            $original = trim((string) $request->input('duplicateOf', ''));
            $triage['duplicate_of'] = $original === '' ? null : $this->validatedOriginal($row, $original);
        }
        $changes = [...$changes, ...$this->withTriage($row, $changes, $triage)];
        // Its plan (2026-10-22, user): the dates, then the epic and the team, each one Jira has.
        foreach (['startOn' => ['start_on', 'start'], 'dueOn' => ['due_on', 'due']] as $input => [$column, $word]) {
            if ($request->has($input)) {
                $changes[$column] = $this->validatedDay($request->input($input), 'Write the '.$word.' date as YYYY-MM-DD, like 2026-10-30.');
            }
        }
        if (array_key_exists('start_on', $changes) || array_key_exists('due_on', $changes)) {
            $start = array_key_exists('start_on', $changes) ? $changes['start_on'] : $row->start_on;
            $due = array_key_exists('due_on', $changes) ? $changes['due_on'] : $row->due_on;
            if ($start !== null && $due !== null && substr((string) $start, 0, 10) > substr((string) $due, 0, 10)) {
                abort(422, 'It cannot start after it is due.');
            }
        }
        if ($request->has('parentKey')) {
            $changes = [...$changes, ...$this->validatedParent($request->input('parentKey'))];
        }
        if ($request->has('teamId')) {
            $changes = [...$changes, ...$this->validatedTeam($request->input('teamId'))];
        }
        if ($changes === []) {
            abort(422, 'Change its kind, status, note, who handles it, its dates, parent, team or version.');
        }

        return $changes;
    }

    /**
     * Who has a followed issue in Jira, against who handles it here: the same, nobody while someone here has it -- an
     * MIS member Jira has no account for -- or someone outside MIS, by the name Jira shows. Null for an issue this
     * server does not follow, a report never sent, or while the field sync is off and nobody's assignee is compared.
     *
     * @return array{state: string, name: ?string}|null
     */
    private function jiraAssignee(object $row, ?string $elsewhere, bool $sync): ?array
    {
        if (! $sync || $elsewhere !== null || $row->jira_issue_id === null || $row->jira_state === PortalBugReportsJira::STATE_DELETED) {
            return null;
        }
        $agreed = $row->jira_assignee_id ?? null;
        if ($row->assignee_id === null && $agreed !== null) {
            return ['state' => 'other', 'name' => $row->jira_assignee_name ?? null];
        }

        return ['state' => $row->assignee_id !== null && $agreed === null ? 'unassigned' : 'same', 'name' => null];
    }

    // A day the calendar has, as YYYY-MM-DD; nothing clears it.
    private function validatedDay(mixed $value, string $message): ?string
    {
        $day = trim((string) $value);
        if ($day === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            abort(422, $message);
        }

        return $day;
    }

    /**
     * The epic a report sits under: one of the project's, asked of Jira afresh once before a key it does not know
     * is refused. Nothing clears it.
     *
     * @return array{parent_key: ?string, parent_summary: ?string}
     */
    private function validatedParent(mixed $value): array
    {
        $key = strtoupper(trim((string) $value));
        if ($key === '') {
            return ['parent_key' => null, 'parent_summary' => null];
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*-\d+$/', $key) !== 1) {
            abort(422, 'Choose an epic for its parent.');
        }
        foreach ([false, true] as $fresh) {
            foreach ($this->planList('epics', $fresh) as $epic) {
                if (strtoupper((string) $epic['key']) === $key) {
                    return ['parent_key' => (string) $epic['key'], 'parent_summary' => $epic['summary'] === '' ? null : (string) $epic['summary']];
                }
            }
        }
        abort(422, $key.' is not an epic in '.$this->jira->projectLabel().'.');
    }

    /**
     * The team that owns a report: one of the site's, asked of Jira afresh once before one it does not know is
     * refused. Nothing clears it.
     *
     * @return array{team_id: ?string, team_name: ?string}
     */
    private function validatedTeam(mixed $value): array
    {
        $id = trim((string) $value);
        if ($id === '') {
            return ['team_id' => null, 'team_name' => null];
        }
        foreach ([false, true] as $fresh) {
            foreach ($this->planList('teams', $fresh) as $team) {
                if ((string) $team['id'] === $id) {
                    return ['team_id' => $id, 'team_name' => (string) $team['name']];
                }
            }
        }
        abort(422, 'Choose one of the teams in Jira.');
    }

    /**
     * Jira's epics or teams for a choice made here, or why they cannot be had.
     *
     * @return list<array<string, mixed>>
     */
    private function planList(string $which, bool $fresh): array
    {
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        try {
            return $this->jira->planList($which, $fresh);
        } catch (RuntimeException $error) {
            abort(503, 'Jira could not list its '.$which.': '.$error->getMessage());
        }
    }

    /**
     * MIS sends a report to Jira by hand, tries a failed one again, or syncs one already there (2026-10-20,
     * user): its missing screenshots go up and it is read back, and an issue Jira no longer has is made anew.
     * The answer says where it got to; the log names only an issue this made.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function sendToJira(Employee $actor, int $id, Request $request): array
    {
        $portal = DB::connection('portal');
        $row = $this->changeableRow($id);
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        // Another server's report, or a link into another project, is that server's to send (2026-10-21, user).
        $elsewhere = $this->jira->whyNotHandled($row);
        if ($elsewhere !== null) {
            abort(409, $elsewhere);
        }
        // By hand, a report starts its automatic tries over.
        $portal->table('bug_reports')->where('id', $id)->update(['jira_attempts' => 0]);
        if (! $this->jira->push($id)) {
            abort(409, 'It is being sent right now.');
        }

        $saved = $portal->table('bug_reports')->where('id', $id)->first();
        if ($saved->jira_state === PortalBugReportsJira::STATE_SENT && $saved->jira_issue_id !== $row->jira_issue_id) {
            $this->audit->record(
                $actor,
                PortalLogAction::POST,
                'bug-reports',
                PortalActivityCopy::sentBugReportToJira((string) $saved->title, self::KINDS[$this->kindOf($saved)]['noun'], (string) $saved->jira_issue_key),
                (string) $id,
                $request,
                ['jiraIssueKey' => $saved->jira_issue_key],
            );
        }

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($saved)];
    }

    /**
     * Refresh (2026-10-19, user): every linked report read back from Jira now, rather than at the next webhook
     * or the quarter-hour run, then the list. Only reads Jira, so it is anyone's who sees the list; a Jira that
     * refuses or does not answer still returns the list, and its connection says why.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, assignees: list<array{id: string, name: string}>, jira: array<string, mixed>}
     */
    public function refreshFromJira(bool $withArchived = false): array
    {
        if ($this->jira->isConfigured()) {
            try {
                // An epic made in Jira since is offered now, rather than in ten minutes.
                $this->jira->forgetEpics();
                $this->jira->reconcile();
            } catch (RuntimeException) {
                // The client has already recorded the connection the list reports.
            }
        }

        return $this->list($withArchived);
    }

    /**
     * What a report's plan is picked from (2026-10-22, user): Jira's teams and epics, the team new issues go to,
     * which plan fields the site has, and which MIS members Jira knows. Asked apart from the list, which never waits
     * on Jira; only reads, so it is anyone's who sees the list.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function jiraOptions(): array
    {
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        try {
            $options = $this->jira->planOptions();
        } catch (RuntimeException $error) {
            abort(503, 'Jira did not list its teams and epics: '.$error->getMessage());
        }

        return ['section' => 'bug-reports', 'resource' => 'jira-options', 'data' => $options];
    }

    /**
     * Every report not in Jira yet -- never sent, or failed -- put on its way there at once (2026-10-20, user:
     * production had reports from before Jira). Queued rather than sent here, since each one is a create and
     * its screenshots; the queue starts as this answers, and the list comes back with each one Queued.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, assignees: list<array{id: string, name: string}>, jira: array<string, mixed>, queued: int}
     */
    public function sendAllToJira(Employee $actor, Request $request, bool $withArchived = false): array
    {
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        $ids = $this->jira->queueUnsent();
        foreach ($ids as $id) {
            Queue::push(new SendBugReportToJira($id));
        }
        if ($ids !== []) {
            PortalQueueNow::afterResponse();
            $this->audit->record($actor, PortalLogAction::POST, 'bug-reports', PortalActivityCopy::sentBugReportsToJira(count($ids)), null, $request, ['queued' => count($ids)]);
        }

        return [...$this->list($withArchived), 'queued' => count($ids)];
    }

    /**
     * MIS archives a report out of the way, or brings it back (2026-10-21, user). Archiving an open linked one
     * moves its issue to Cancelled first; unarchiving never touches Jira. Asking for what it already is changes
     * nothing.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function archive(Employee $actor, int $id, Request $request): array
    {
        $archived = $request->input('archived');
        if (! is_bool($archived)) {
            abort(422, 'Say whether to archive it or bring it back.');
        }
        $portal = DB::connection('portal');
        $row = $this->liveRow($id);
        if (($row->archived_at !== null) === $archived) {
            return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($row)];
        }
        $moved = null;
        $changed = false;
        $held = $this->jira->withReportLock($id, function () use ($actor, $id, $request, $archived, $portal, &$moved, &$changed): void {
            $row = $this->liveRow($id);
            if (($row->archived_at !== null) === $archived) {
                return;
            }
            if ($archived) {
                $moved = $this->cancelIssueBeforeRemoval($actor, $row, $request, 'Archiving');
            }
            $portal->table('bug_reports')->where('id', $id)->update($archived
                ? ['archived_at' => now('UTC')->format('Y-m-d H:i:s'), 'archived_by' => (int) $actor->getKey()]
                : ['archived_at' => null, 'archived_by' => null]);
            $changed = true;
        });
        if (! $held) {
            abort(409, self::BEING_SENT);
        }

        $saved = $this->liveRow($id);
        if (! $changed) {
            return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($saved)];
        }
        $noun = self::KINDS[$this->kindOf($saved)]['noun'];
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'bug-reports',
            $archived ? PortalActivityCopy::archivedBugReport((string) $saved->title, $noun, $moved) : PortalActivityCopy::unarchivedBugReport((string) $saved->title, $noun),
            (string) $id,
            $request,
            ['archived' => $archived, 'jiraCancelled' => $moved],
        );

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($saved)];
    }

    /**
     * Before a report is archived or deleted: an open linked one's issue moved to Cancelled, which needs Send to
     * Jira and a Jira that takes it -- or nothing changes. A closed one, one never sent and another server's are
     * removed without Jira. The key of an issue this moved, else null. Called under the report's lock.
     */
    public function cancelIssueBeforeRemoval(Employee $actor, object $row, Request $request, string $doing): ?string
    {
        if (! $this->jira->owns($row) || in_array($row->status, [self::STATUS_FIXED, self::STATUS_CANCELLED], true)) {
            return null;
        }
        if (! PortalRules::holds($actor, $request, PortalBugReportsJira::RULE)) {
            abort(403, $doing.' it moves '.$row->jira_issue_key.' to Cancelled in Jira, which needs Send to Jira.');
        }
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        try {
            return $this->jira->cancelForRemoval($row);
        } catch (RuntimeException $error) {
            abort(422, 'Jira did not take the change: '.$error->getMessage());
        }
    }

    // A report on the page, or 404: a deleted one is in its deleter's Recycle Bin, not here.
    public function liveRow(int $id): object
    {
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->whereNull('deleted_at')->first();
        if ($row === null) {
            abort(404, 'That issue or request was not found.');
        }

        return $row;
    }

    // A report that can change: on the page and not archived.
    private function changeableRow(int $id): object
    {
        $row = $this->liveRow($id);
        if ($row->archived_at !== null) {
            abort(409, 'It is archived: unarchive it to change it.');
        }

        return $row;
    }

    /**
     * MIS turns sending new reports to Jira on its own on or off.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateJira(Employee $actor, Request $request): array
    {
        // Either switch, or both (2026-10-22, user: keeping the fields in step, off until turned on).
        $autoSend = $request->input('autoSend');
        $syncFields = $request->input('syncFields');
        if (($request->has('autoSend') && ! is_bool($autoSend)) || ($request->has('syncFields') && ! is_bool($syncFields)) || (! is_bool($autoSend) && ! is_bool($syncFields))) {
            abort(422, 'Say whether new ones go to Jira on their own, or whether their fields are kept in step with Jira.');
        }
        if (is_bool($autoSend)) {
            $this->jira->setAutoSend($autoSend);
            $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports', PortalActivityCopy::switchedJiraAutoSend($autoSend), null, $request, ['autoSend' => $autoSend]);
        }
        if (is_bool($syncFields)) {
            $this->jira->setSyncFields($syncFields);
            $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports', PortalActivityCopy::switchedJiraFieldSync($syncFields), null, $request, ['syncFields' => $syncFields]);
        }

        return ['section' => 'bug-reports', 'resource' => 'jira', 'data' => $this->jira->settings()];
    }

    /**
     * One report as the page shows it.
     *
     * @return array<string, mixed>
     */
    private function item(object $row): array
    {
        $images = DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $row->id)->orderBy('id')->get()->all();
        $titles = $row->duplicate_of === null ? [] : [(int) $row->duplicate_of => DB::connection('portal')->table('bug_reports')->where('id', $row->duplicate_of)->value('title')];
        $row->other_assignees = PortalBugReportAssignees::othersOf([(int) $row->id])[(int) $row->id] ?? [];

        return $this->present($row, $images, $this->namesOf([$row->assignee_id, ...$row->other_assignees, $row->reporter_id, $row->archived_by]), array_filter($titles, fn ($title): bool => $title !== null));
    }

    /**
     * What the log says, by the change that matters most: a new status, then who handles it, then its kind.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int, string>  $names
     */
    private function logMessage(Employee $actor, object $row, array $changes, array $names): string
    {
        $title = (string) $row->title;
        $was = $this->kindOf($row);
        $noun = self::KINDS[$changes['kind'] ?? $was]['noun'];
        // Closed now, or closed for another reason: a duplicate names what it duplicates, a cancelled one says why.
        $triaged = (isset($changes['status']) && $changes['status'] !== $row->status) || array_key_exists('cancel_reason', $changes) || array_key_exists('duplicate_of', $changes);
        $status = $changes['status'] ?? $row->status;
        $reason = array_key_exists('cancel_reason', $changes) ? $changes['cancel_reason'] : $row->cancel_reason;
        $original = array_key_exists('duplicate_of', $changes) ? $changes['duplicate_of'] : $row->duplicate_of;
        if ($triaged && self::isDuplicate($status, $reason) && $original !== null) {
            return PortalActivityCopy::duplicateBugReport($title, $noun, $this->originalLabel((int) $original), $status === self::STATUS_FIXED);
        }
        if ($triaged && $status === self::STATUS_CANCELLED) {
            return PortalActivityCopy::cancelledBugReport($title, $noun);
        }
        if (isset($changes['status']) && $changes['status'] !== $row->status) {
            return PortalActivityCopy::updatedBugReport($title, self::STATUS_LABELS[$changes['status']], $noun);
        }
        $before = array_values(array_filter([$row->assignee_id === null ? null : (int) $row->assignee_id, ...($row->other_assignees ?? [])]));
        $after = array_key_exists('assignee_id', $changes)
            ? array_values(array_filter([$changes['assignee_id'], ...($changes['other_assignees'] ?? [])]))
            : $before;
        if ($after !== $before) {
            $me = (int) $actor->getKey();
            $took = in_array($me, $after, true) && ! in_array($me, $before, true) && array_values(array_diff($after, [$me])) === $before;

            return PortalActivityCopy::assignedBugReport(
                $title,
                array_map(fn (int $id): string => $names[$id] ?? 'Member', $took ? $before : $after),
                $took,
                $noun,
            );
        }
        if (isset($changes['kind']) && $changes['kind'] !== $was) {
            return PortalActivityCopy::reclassifiedBugReport($title, self::KINDS[$was]['noun'], self::KINDS[$changes['kind']]['as']);
        }

        return PortalActivityCopy::updatedBugReport($title, null, $noun);
    }

    // A kind the page knows, or the default when none is sent -- or, with no default, refused when blank.
    private function validatedKind(mixed $value, ?string $default): string
    {
        $kind = strtolower(trim((string) $value));
        if ($kind === '' && $default !== null) {
            return $default;
        }
        if (! array_key_exists($kind, self::KINDS)) {
            abort(422, 'Choose Bug, Feature request or Feedback.');
        }

        return $kind;
    }

    // A row's kind; an unknown value reads as a bug, as every report before kinds was.
    private function kindOf(object $row): string
    {
        $kind = (string) ($row->kind ?? self::KIND_BUG);

        return array_key_exists($kind, self::KINDS) ? $kind : self::KIND_BUG;
    }

    /**
     * Active MIS members by id, in the order chosen and each once; none unassigns it.
     *
     * @return list<int>
     */
    private function validatedAssignees(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            abort(422, 'Choose someone in MIS to handle it.');
        }
        $chosen = [];
        foreach ($value as $id) {
            $member = $this->validatedAssignee(is_scalar($id) ? $id : '-');
            if ($member !== null && ! in_array($member, $chosen, true)) {
                $chosen[] = $member;
            }
        }
        if (count($chosen) > PortalBugReportAssignees::MAX) {
            abort(422, 'Choose at most '.PortalBugReportAssignees::MAX.' people to handle it.');
        }

        return $chosen;
    }

    // An active MIS member by id, or null to unassign; anyone else is refused.
    private function validatedAssignee(mixed $value): ?int
    {
        $id = trim((string) $value);
        if ($id === '') {
            return null;
        }
        $isTeam = ctype_digit($id) && Employee::query()
            ->whereKey((int) $id)
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [self::TEAM])
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->exists();
        if (! $isTeam) {
            abort(422, 'Choose someone in MIS to handle it.');
        }

        return (int) $id;
    }

    /**
     * The active MIS members a report can be given to, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    private function assignees(): array
    {
        return Employee::query()
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [self::TEAM])
            ->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Employee $employee): array => [
                'id' => (string) $employee->getKey(),
                'name' => PortalSubmittedReportPresenter::memberName($employee->first_name, $employee->last_name),
            ])
            ->values()
            ->all();
    }

    /**
     * Names by employee id, for the people reports are assigned to -- whoever they are now.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function namesOf(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn (mixed $id): bool => $id !== null))));
        if ($ids === []) {
            return [];
        }

        return Employee::query()
            ->whereKey($ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Employee $employee): array => [
                (int) $employee->getKey() => PortalSubmittedReportPresenter::memberName($employee->first_name, $employee->last_name),
            ])
            ->all();
    }

    /**
     * @param  list<object>  $images
     * @param  array<int, string>  $names
     * @param  array<int, string>  $titles
     * @return array<string, mixed>
     */
    private function present(object $row, array $images, array $names, array $titles = [], ?bool $sync = null): array
    {
        $assigneeId = $row->assignee_id === null ? null : (int) $row->assignee_id;
        $reporterId = $row->reporter_id === null ? null : (int) $row->reporter_id;
        $archivedBy = $row->archived_by === null ? null : (int) $row->archived_by;
        $elsewhere = $this->jira->whyNotHandled($row);

        return [
            'id' => (string) $row->id,
            'kind' => $this->kindOf($row),
            'title' => (string) $row->title,
            'description' => (string) $row->description,
            'page' => $row->page,
            'status' => (string) $row->status,
            'note' => $row->note,
            'reportedOn' => substr((string) $row->reported_on, 0, 10),
            'assignee' => $assigneeId === null ? null : ['id' => (string) $assigneeId, 'name' => $names[$assigneeId] ?? 'Member'],
            // Everyone who works on it (2026-10-22, user), the assignee first.
            'assignees' => array_map(fn (int $id): array => ['id' => (string) $id, 'name' => $names[$id] ?? 'Member'],
                array_values(array_filter([$assigneeId, ...($row->other_assignees ?? [])]))),
            // Who sent it, only when they chose to be named; null is anonymous.
            'reporter' => $reporterId === null ? null : ['id' => (string) $reporterId, 'name' => $names[$reporterId] ?? 'Member'],
            'versionLevel' => $row->version_level,
            'fixedIn' => $row->fixed_in,
            // Its plan (2026-10-22, user): the dates, the epic it sits under and the team, each the issue's too.
            'startOn' => ($row->start_on ?? null) === null ? null : substr((string) $row->start_on, 0, 10),
            'dueOn' => ($row->due_on ?? null) === null ? null : substr((string) $row->due_on, 0, 10),
            'parent' => ($row->parent_key ?? null) === null ? null : ['key' => (string) $row->parent_key, 'summary' => $row->parent_summary],
            'team' => ($row->team_id ?? null) === null ? null : ['id' => (string) $row->team_id, 'name' => $row->team_name],
            'images' => array_map(fn (object $image): array => [
                'url' => (string) $image->file_url,
                'mimeType' => (string) $image->mime_type,
                'thumbUrl' => $this->cloudinary->thumbUrl((string) $image->file_url, (string) $image->mime_type),
            ], $images),
            'jira' => $this->jira->present($row),
            // Whether this server follows its Jira side; false for another server's report (2026-10-21, user).
            'jiraHere' => $elsewhere === null,
            // And why not, in the words a refusal to send it uses: a page that guessed blamed another server for a
            // project whose key had changed (2026-10-22).
            'jiraElsewhere' => $elsewhere,
            // Who has it in Jira while this server follows the issue (2026-10-22, user).
            'jiraAssignee' => $this->jiraAssignee($row, $elsewhere, $sync ?? $this->jira->syncFields()),
            // Why a closed one was closed, and the report a duplicate names (2026-10-21, 2026-10-22, user).
            'cancelReason' => match ((string) $row->status) {
                self::STATUS_CANCELLED => $row->cancel_reason ?? self::CANCEL_WONT_FIX,
                self::STATUS_FIXED => $row->cancel_reason === self::CANCEL_DUPLICATE ? self::CANCEL_DUPLICATE : null,
                default => null,
            },
            'duplicateOf' => $row->duplicate_of === null ? null : ['id' => (string) $row->duplicate_of, 'title' => $titles[(int) $row->duplicate_of] ?? null],
            // Archived out of the way, when and by whom (2026-10-21, user); null while on the page.
            'archivedAt' => $row->archived_at === null ? null : str_replace(' ', 'T', substr((string) $row->archived_at, 0, 19)).'Z',
            'archivedBy' => $archivedBy === null ? null : ['id' => (string) $archivedBy, 'name' => $names[$archivedBy] ?? 'Member'],
        ];
    }

    /**
     * What triage changes: a fixed one may be a duplicate of the one it names, a cancelled one is won't fix, and any
     * other status clears both. Only what differs from the row, so a save that touches neither changes neither -- an
     * older app that sends only a Cancelled status gets Won't fix, a duplicate moved between Fixed and Cancelled stays
     * one, and one cancelled as a duplicate before 2026-10-22 stays as it is.
     *
     * @param  array<string, mixed>  $changes
     * @param  array{cancel_reason?: ?string, duplicate_of?: ?int}  $triage
     * @return array<string, mixed>
     */
    private function withTriage(object $row, array $changes, array $triage): array
    {
        if (! isset($changes['status']) && $triage === []) {
            return [];
        }
        $status = $changes['status'] ?? (string) $row->status;
        $wasDuplicate = self::isDuplicate($row->status, $row->cancel_reason);
        $reason = null;
        $original = null;
        if (! in_array($status, [self::STATUS_FIXED, self::STATUS_CANCELLED], true)) {
            $asked = $triage['cancel_reason'] ?? null;
            if ($asked === self::CANCEL_DUPLICATE || ($triage['duplicate_of'] ?? null) !== null) {
                abort(422, self::ONLY_FIXED_DUPLICATES);
            }
            if ($asked === self::CANCEL_WONT_FIX) {
                abort(422, self::ONLY_CANCELLED_WONT_FIX);
            }
        } else {
            $saved = match (true) {
                $wasDuplicate && ($status === self::STATUS_FIXED || $row->status === $status) => self::CANCEL_DUPLICATE,
                $status === self::STATUS_CANCELLED => $row->status === $status ? ($row->cancel_reason ?? self::CANCEL_WONT_FIX) : self::CANCEL_WONT_FIX,
                default => null,
            };
            $reason = array_key_exists('cancel_reason', $triage) ? $triage['cancel_reason'] : $saved;
            if ($status === self::STATUS_CANCELLED) {
                $reason ??= self::CANCEL_WONT_FIX;
                // A duplicate cancelled before 2026-10-22 stays one while it stays cancelled; a new one is fixed.
                if ($reason === self::CANCEL_DUPLICATE && ! ($wasDuplicate && $row->status === $status)) {
                    abort(422, self::ONLY_FIXED_DUPLICATES);
                }
            } elseif ($reason === self::CANCEL_WONT_FIX) {
                abort(422, self::ONLY_CANCELLED_WONT_FIX);
            }
            $original = array_key_exists('duplicate_of', $triage) ? $triage['duplicate_of'] : ($wasDuplicate && $row->duplicate_of !== null ? (int) $row->duplicate_of : null);
            if ($reason === self::CANCEL_DUPLICATE && $original === null) {
                abort(422, 'Choose the issue or request it duplicates.');
            }
            if ($reason !== self::CANCEL_DUPLICATE) {
                if (($triage['duplicate_of'] ?? null) !== null) {
                    abort(422, 'Only a duplicate names the one it duplicates.');
                }
                $original = null;
            }
        }
        $changed = [];
        if ($reason !== $row->cancel_reason) {
            $changed['cancel_reason'] = $reason;
        }
        if ($original !== ($row->duplicate_of === null ? null : (int) $row->duplicate_of)) {
            $changed['duplicate_of'] = $original;
        }

        return $changed;
    }

    // The report a duplicate duplicates: another one, not a duplicate itself, so no chain or loop forms.
    private function validatedOriginal(object $row, string $value): int
    {
        if (! ctype_digit($value)) {
            abort(422, 'Choose the issue or request it duplicates.');
        }
        $id = (int) $value;
        if ($id === (int) $row->id) {
            abort(422, 'It cannot be a duplicate of itself.');
        }
        $original = DB::connection('portal')->table('bug_reports')->where('id', $id)->whereNull('deleted_at')->first(['id', 'status', 'cancel_reason', 'duplicate_of']);
        if ($original === null) {
            abort(422, 'There is no #'.$id.' to be a duplicate of.');
        }
        if (self::isDuplicate($original->status, $original->cancel_reason) && $original->duplicate_of !== null) {
            abort(422, '#'.$id.' is a duplicate itself: choose #'.$original->duplicate_of.', the one it duplicates.');
        }

        return $id;
    }

    // A report as the log names the one a duplicate duplicates: its number and title.
    private function originalLabel(int $id): string
    {
        $title = DB::connection('portal')->table('bug_reports')->where('id', $id)->value('title');

        return '#'.$id.($title === null ? '' : ' '.$title);
    }

    // The sender's id when they chose to be named; anonymous, or saying nothing, keeps none.
    private function namedSender(Request $request, Employee $sender): ?int
    {
        if (! $request->has('anonymous')) {
            return null;
        }
        $anonymous = filter_var($request->input('anonymous'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($anonymous === null) {
            abort(422, 'Say whether to send it anonymously.');
        }

        return $anonymous ? null : (int) $sender->getKey();
    }

    /**
     * @return list<UploadedFile>
     */
    private function validatedImages(Request $request): array
    {
        $files = $request->file('images', []);
        $files = is_array($files) ? array_values($files) : [$files];
        if (count($files) > self::MAX_IMAGES) {
            abort(422, 'Attach up to three screenshots.');
        }
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                abort(422, 'A screenshot could not be read. Choose it again.');
            }
            if ($file->getSize() > self::MAX_IMAGE_BYTES) {
                abort(422, 'A screenshot cannot be larger than 5 MB.');
            }
            if (! in_array($file->getMimeType(), self::IMAGE_MIMES, true)) {
                abort(422, 'A screenshot has to be a JPEG, PNG, WebP or GIF.');
            }
        }

        return $files;
    }

    // A transformation, so Cloudinary serves the picture without the EXIF a phone writes into it.
    private function withoutMetadata(string $url): string
    {
        $served = preg_replace('#/upload/#', '/upload/q_auto/', $url, 1);

        return is_string($served) ? $served : $url;
    }

    private function requiredText(Request $request, string $key, int $max, string $missing, string $tooLong): string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            abort(422, $missing);
        }
        if (mb_strlen($value) > $max) {
            abort(422, $tooLong);
        }

        return $value;
    }

    private function optionalText(Request $request, string $key, int $max, ?string $tooLong = null): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            abort(422, $tooLong ?? 'That text is too long.');
        }

        return $value;
    }
}
