<?php

namespace App\Support\Portal;

use App\Jobs\SendBugReportToJira;
use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Bug reports (2026-10-13, user): anyone signed in sends one from the button in the portal's corner,
 * with up to three screenshots, and MIS reads them on Bug Reports and tracks each to fixed.
 *
 * A report is anonymous, and that is enforced here rather than left to the page: submit() is never
 * handed the person, writes no activity log, needs no location, and keeps only the day it came in.
 * A screenshot goes up under a neutral name and is served through a Cloudinary transformation, which
 * drops what a photo carries about the phone that took it. The only trace of the sender is the rate
 * limiter's hour-long counter, which never meets the report it counted.
 *
 * MIS hears of a new report on the bell (2026-10-13, user). The notice is from no one: it names no
 * actor, and it goes to everyone who holds Bug Reports -- the sender too, when they do -- so nobody
 * can tell who sent it by who was left out.
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
 * Each report becomes a Jira issue (PortalBugReportsJira). Once it is one, Jira owns its status, its
 * fix version and its kind, so those are refused here and read back from Jira instead.
 */
final class PortalBugReports
{
    public const STATUS_NEW = 'new';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FIXED = 'fixed';

    public const STATUS_WONT_FIX = 'wont_fix';

    /**
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_IN_PROGRESS => 'In progress',
        self::STATUS_FIXED => 'Fixed',
        self::STATUS_WONT_FIX => "Won't fix",
    ];

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
    public function submit(Request $request): array
    {
        $kind = $this->validatedKind($request->input('kind'), self::KIND_BUG);
        $title = $this->requiredText($request, 'title', self::MAX_TITLE, 'Give it a short title.', 'Keep the title under 160 characters.');
        $description = $this->requiredText($request, 'description', self::MAX_DESCRIPTION, 'Say what happened or what you need.', 'Keep the description under 5,000 characters.');
        $page = $this->optionalText($request, 'page', self::MAX_PAGE);
        $images = $this->validatedImages($request);

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
        $id = DB::connection('portal')->transaction(function () use ($kind, $title, $description, $page, $stored, $toJira): int {
            $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
                'kind' => $kind,
                'jira_state' => $toJira ? PortalBugReportsJira::STATE_PENDING : null,
                'title' => $title,
                'description' => $description,
                'page' => $page,
                'status' => self::STATUS_NEW,
                'note' => null,
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
            '"'.$title.'" was '.self::KINDS[$kind]['verb'].($page === null ? '' : ' on '.$page).'.',
            PortalShellPath::BUG_REPORTS,
            'Open Issues & Requests',
            ['recordId' => (string) $id],
        );
        if ($toJira) {
            Queue::push(new SendBugReportToJira($id));
            PortalQueueNow::afterResponse();
        }

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => ['id' => (string) $id]];
    }

    /**
     * Every report, newest first, with its screenshots and who handles it, and the MIS members it can be
     * given to. Nothing in a row says who sent it.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, assignees: list<array{id: string, name: string}>, jira: array<string, mixed>}
     */
    public function list(): array
    {
        $portal = DB::connection('portal');
        $rows = $portal->table('bug_reports')->orderByDesc('reported_on')->orderByDesc('id')->get();
        $images = $portal->table('bug_report_images')
            ->whereIn('bug_report_id', $rows->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('bug_report_id');
        $names = $this->namesOf($rows->pluck('assignee_id')->filter()->all());

        return [
            'section' => 'bug-reports',
            'resource' => 'bug-reports',
            'data' => $rows->map(fn (object $row): array => $this->present($row, $images->get($row->id, collect())->all(), $names))->values()->all(),
            'assignees' => $this->assignees(),
            'jira' => $this->jira->settings(),
        ];
    }

    /**
     * MIS moves a report along: its kind, status, note, who handles it or its version. Only the fields sent change.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, int $id, Request $request): array
    {
        $portal = DB::connection('portal');
        $row = $portal->table('bug_reports')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That issue or request was not found.');
        }

        if ($this->jira->owns($row)) {
            $key = (string) $row->jira_issue_key;
            if ($request->has('status')) {
                abort(422, 'Its status is managed in Jira ('.$key.').');
            }
            if ($request->has('fixedIn')) {
                abort(422, 'Its fixed-in version is managed in Jira ('.$key.').');
            }
            if ($request->has('kind')) {
                abort(422, 'Its kind is set in Jira ('.$key.').');
            }
        }

        $changes = [];
        if ($request->has('kind')) {
            $changes['kind'] = $this->validatedKind($request->input('kind'), null);
        }
        if ($request->has('status')) {
            $status = (string) $request->input('status');
            if (! array_key_exists($status, self::STATUS_LABELS)) {
                abort(422, "Choose New, In progress, Fixed or Won't fix.");
            }
            $changes['status'] = $status;
        }
        if ($request->has('note')) {
            $changes['note'] = $this->optionalText($request, 'note', self::MAX_NOTE, 'Keep the note under 2,000 characters.');
        }
        if ($request->has('assigneeId')) {
            $changes['assignee_id'] = $this->validatedAssignee($request->input('assigneeId'));
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
        if ($changes === []) {
            abort(422, 'Change its kind, status, note, who handles it or its version.');
        }
        $handler = array_key_exists('assignee_id', $changes) ? $changes['assignee_id'] : $row->assignee_id;
        if ($handler === null && array_diff(array_keys($changes), ['assignee_id']) !== []) {
            abort(422, 'Assign someone to handle it before changing it.');
        }

        $portal->table('bug_reports')->where('id', $id)->update([...$changes, 'updated_at' => now('UTC')->format('Y-m-d H:i:s')]);

        $saved = $portal->table('bug_reports')->where('id', $id)->first();
        $names = $this->namesOf([$saved->assignee_id]);
        $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports', $this->logMessage($actor, $row, $changes, $names), (string) $id, $request, $changes);

        return ['section' => 'bug-reports', 'resource' => 'bug-reports', 'data' => $this->item($saved)];
    }

    /**
     * MIS sends a report to Jira by hand, or tries a failed one again; the answer says where it got to.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function sendToJira(Employee $actor, int $id, Request $request): array
    {
        $portal = DB::connection('portal');
        $row = $portal->table('bug_reports')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That issue or request was not found.');
        }
        if (! $this->jira->isConfigured()) {
            abort(503, 'Jira is not connected yet.');
        }
        if ($this->jira->owns($row) && $this->jira->isFullySent($row)) {
            abort(409, 'It is already in Jira as '.$row->jira_issue_key.'.');
        }
        // By hand, a report starts its automatic tries over.
        $portal->table('bug_reports')->where('id', $id)->update(['jira_attempts' => 0]);
        if (! $this->jira->push($id)) {
            abort(409, 'It is being sent right now.');
        }

        $saved = $portal->table('bug_reports')->where('id', $id)->first();
        if ($saved->jira_state === PortalBugReportsJira::STATE_SENT) {
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
    public function refreshFromJira(): array
    {
        if ($this->jira->isConfigured()) {
            try {
                $this->jira->reconcile();
            } catch (RuntimeException) {
                // The client has already recorded the connection the list reports.
            }
        }

        return $this->list();
    }

    /**
     * MIS turns sending new reports to Jira on its own on or off.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateJira(Employee $actor, Request $request): array
    {
        $on = $request->input('autoSend');
        if (! is_bool($on)) {
            abort(422, 'Say whether new ones go to Jira on their own.');
        }
        $this->jira->setAutoSend($on);
        $this->audit->record($actor, PortalLogAction::PATCH, 'bug-reports', PortalActivityCopy::switchedJiraAutoSend($on), null, $request, ['autoSend' => $on]);

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

        return $this->present($row, $images, $this->namesOf([$row->assignee_id]));
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
        if (isset($changes['status']) && $changes['status'] !== $row->status) {
            return PortalActivityCopy::updatedBugReport($title, self::STATUS_LABELS[$changes['status']], $noun);
        }
        $before = $row->assignee_id === null ? null : (int) $row->assignee_id;
        if (array_key_exists('assignee_id', $changes) && $changes['assignee_id'] !== $before) {
            $assignee = $changes['assignee_id'];

            return PortalActivityCopy::assignedBugReport(
                $title,
                $assignee === null ? null : ($names[$assignee] ?? null),
                $assignee === (int) $actor->getKey(),
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
     * @return array<string, mixed>
     */
    private function present(object $row, array $images, array $names): array
    {
        $assigneeId = $row->assignee_id === null ? null : (int) $row->assignee_id;

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
            'versionLevel' => $row->version_level,
            'fixedIn' => $row->fixed_in,
            'images' => array_map(fn (object $image): array => [
                'url' => (string) $image->file_url,
                'mimeType' => (string) $image->mime_type,
                'thumbUrl' => $this->cloudinary->thumbUrl((string) $image->file_url, (string) $image->mime_type),
            ], $images),
            'jira' => $this->jira->present($row),
        ];
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
