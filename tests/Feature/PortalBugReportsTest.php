<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

// Bug reports (2026-10-13, user): anyone reports one anonymously, with screenshots; MIS reads and tracks them.
// Issues & Requests (2026-10-19, user): a report is a bug, a feature request or feedback, and MIS can refile it.
class PortalBugReportsTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/';

    private const UPLOAD = 'https://api.cloudinary.com/v1_1/test-cloud/auto/upload';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_anyone_reports_a_bug_with_screenshots_and_nothing_names_them(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::UPLOAD => Http::response([
            'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/bug-reports/shot.png',
        ], 200)]);
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $logged = PortalLog::query()->count();

        // No location either: a report is not asked where it was sent from.
        $this->flushHeaders();
        $id = $this->withHeaders($headers)->post(self::BASE.'bug-reports', [
            'title' => '  Save does nothing on Transactions ',
            'description' => 'Pressing Save Changes leaves the form open.',
            'page' => 'finance/transactions',
            'images' => [UploadedFile::fake()->image('jamie-phone.jpg'), UploadedFile::fake()->image('second.png')],
        ])->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame('Save does nothing on Transactions', $row->title);
        $this->assertSame('finance/transactions', $row->page);
        $this->assertSame('new', $row->status);
        $this->assertSame('bug', $row->kind);
        // No Jira on this server, so nothing waits to go there.
        $this->assertNull($row->jira_state);
        $this->assertSame(now()->toDateString(), substr((string) $row->reported_on, 0, 10));
        // Saying nothing is anonymous (2026-10-20): no sender kept, and no activity was logged for it.
        $this->assertNull($row->reporter_id);
        // The server it was sent on (2026-10-21, user: production and staging share one Jira project).
        $this->assertSame('development', $row->channel);
        $this->assertSame(
            ['id', 'kind', 'title', 'description', 'page', 'status', 'cancel_reason', 'duplicate_of', 'note', 'assignee_id', 'version_level', 'fixed_in',
                'start_on', 'due_on', 'parent_key', 'parent_summary', 'team_id', 'team_name', 'reporter_id', 'channel', 'reported_on', 'updated_at',
                'archived_at', 'archived_by', 'deleted_at', 'jira_issue_id', 'jira_issue_key', 'jira_state', 'jira_status', 'jira_error', 'jira_attached', 'jira_attempts', 'jira_synced_at', 'jira_create_started_at',
                'jira_assignee_id', 'jira_assignee_name'],
            Schema::connection('portal')->getColumnListing('bug_reports'),
        );
        $this->assertSame($logged, PortalLog::query()->count());

        // Each screenshot went up under a neutral name, and is served without its metadata.
        $this->assertSame(
            array_fill(0, 2, 'https://res.cloudinary.com/test-cloud/image/upload/q_auto/v1/ikaika-portal/bug-reports/shot.png'),
            DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $id)->pluck('file_url')->all(),
        );
        $bodies = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]->body());
        $this->assertCount(2, $bodies);
        $this->assertTrue($bodies->every(fn (string $body): bool => ! str_contains($body, 'jamie-phone') && str_contains($body, 'screenshot-')));

        // The reported ask (2026-10-13): MIS hears of it on the bell, from no one, and nobody outside MIS does.
        $notices = PortalNotification::query()->where('type', 'bug-reports.filed')->get()
            ->filter(fn (PortalNotification $notice): bool => ($notice->payload['recordId'] ?? null) === $id);
        $this->assertNotEmpty($notices);
        $this->assertTrue($notices->every(fn (PortalNotification $notice): bool => $notice->actor_id === null
            && $notice->title === 'New bug report'
            && $notice->link_path === '/bug-reports'
            && $notice->link_label === 'Open Issues & Requests'
            && $notice->message === '"Save does nothing on Transactions" was reported on finance/transactions.'));
        $departments = Employee::query()->whereIn('id', $notices->pluck('employee_id'))->pluck('department')
            ->map(fn (?string $department): string => strtolower(trim((string) $department)))->unique()->values()->all();
        $this->assertSame(['mis'], $departments);
    }

    // A PC with no Cloudinary keys (2026-10-20, user): the screenshot is refused by name, and nothing half-saved.
    public function test_a_report_with_screenshots_is_refused_whole_where_cloudinary_is_not_set_up(): void
    {
        Http::preventStrayRequests();
        config(['services.cloudinary.api_secret' => '']);
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $before = DB::connection('portal')->table('bug_reports')->count();

        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [
            'title' => 'In review',
            'description' => 'With a screenshot.',
            'images' => [UploadedFile::fake()->image('shot.png')],
        ])->assertStatus(503)->assertJsonPath('message', 'Screenshots cannot be uploaded on this server yet: Cloudinary is not set up. Send it without them for now.');

        $this->assertSame($before, DB::connection('portal')->table('bug_reports')->count());
        Http::assertNothingSent();
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', ['title' => 'In review', 'description' => 'Without one.'])->assertCreated();
    }

    // Testers asked to be named (2026-10-20, user): anonymous is the sender's choice, and the page, the bell and Jira say who.
    public function test_a_sender_who_chooses_to_be_named_is_and_one_who_does_not_stays_anonymous(): void
    {
        Http::fake();
        $sender = $this->memberOf('Engineering');
        $headers = $this->headersFor($sender);
        $body = ['title' => 'Calendar skips Sunday', 'description' => 'Saturday jumps to Monday.'];

        $named = $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'anonymous' => '0'])->assertCreated()->json('data.id');
        $anonymous = $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'anonymous' => '1'])->assertCreated()->json('data.id');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'anonymous' => 'maybe'])
            ->assertStatus(422)->assertJsonPath('message', 'Say whether to send it anonymously.');

        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$named, $anonymous])->pluck('reporter_id', 'id');
        $this->assertSame([(int) $sender->getKey(), null], [(int) $rows[(int) $named], $rows[(int) $anonymous]]);
        $name = PortalSubmittedReportPresenter::memberName($sender->first_name, $sender->last_name);
        $listed = collect($this->withHeaders($this->headersFor($this->memberOf('MIS')))->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['id' => (string) $sender->getKey(), 'name' => $name], $listed[$named]['reporter']);
        $this->assertNull($listed[$anonymous]['reporter']);

        $notices = PortalNotification::query()->where('type', 'bug-reports.filed')->get()->groupBy(fn (PortalNotification $notice): string => (string) ($notice->payload['recordId'] ?? ''));
        $this->assertTrue($notices[$named]->every(fn (PortalNotification $notice): bool => $notice->actor_id === (int) $sender->getKey()
            && $notice->message === '"Calendar skips Sunday" was reported by '.$name.'.'));
        $this->assertTrue($notices[$anonymous]->every(fn (PortalNotification $notice): bool => $notice->actor_id === null
            && $notice->message === '"Calendar skips Sunday" was reported.'));
    }

    public function test_a_report_needs_a_title_and_a_description_and_three_screenshots_at_most(): void
    {
        Http::fake();
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $body = ['title' => 'Broken', 'description' => 'It broke.'];

        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'title' => ' '])
            ->assertStatus(422)->assertJsonPath('message', 'Give it a short title.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'description' => ''])
            ->assertStatus(422)->assertJsonPath('message', 'Say what happened or what you need.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'kind' => 'question'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose Bug, Feature request or Feedback.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'images' => array_map(
            fn (int $index): UploadedFile => UploadedFile::fake()->image("shot{$index}.png"),
            [1, 2, 3, 4],
        )])->assertStatus(422)->assertJsonPath('message', 'Attach up to three screenshots.');
        $this->withHeaders($headers)->post(self::BASE.'bug-reports', [...$body, 'images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])
            ->assertStatus(422)->assertJsonPath('message', 'A screenshot has to be a JPEG, PNG, WebP or GIF.');

        Http::assertNothingSent();
        // Signed in still: a report is anonymous to MIS, not open to anyone on the internet.
        $this->flushHeaders();
        $this->postJson(self::BASE.'bug-reports', $body)->assertUnauthorized();
    }

    public function test_mis_reads_and_tracks_the_reports_and_nobody_else_does(): void
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Calendar skips Sunday',
            'description' => 'The month view jumps from Saturday to Monday.',
            'page' => 'calendar',
            'status' => 'new',
            'reported_on' => '2026-10-12',
        ]);
        DB::connection('portal')->table('bug_report_images')->insert([
            'bug_report_id' => $id,
            'file_url' => 'https://res.cloudinary.com/test-cloud/image/upload/q_auto/v1/ikaika-portal/bug-reports/cal.png',
            'mime_type' => 'image/png',
            'size_bytes' => 900,
        ]);
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $engineering = $this->headersFor($this->memberOf('Engineering'));

        $listed = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame(['id', 'kind', 'title', 'description', 'page', 'status', 'note', 'reportedOn', 'assignee', 'reporter', 'versionLevel', 'fixedIn', 'startOn', 'dueOn', 'parent', 'team', 'images', 'jira', 'jiraHere', 'jiraElsewhere', 'jiraAssignee', 'cancelReason', 'duplicateOf', 'archivedAt', 'archivedBy'], array_keys($listed));
        $this->assertNull($listed['reporter']);
        $this->assertNull($listed['jira']);
        $this->assertSame('2026-10-12', $listed['reportedOn']);
        $this->assertStringContainsString('c_fill', (string) $listed['images'][0]['thumbUrl']);
        $this->withHeaders($engineering)->getJson(self::BASE.'bug-reports')->assertForbidden();

        // Nobody handles it yet, so nothing else about it moves; assigning it in the same save is enough.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed', 'note' => 'Fixed in the October build.', 'assigneeId' => (string) $analyst->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'fixed')
            ->assertJsonPath('data.note', 'Fixed in the October build.');
        $this->assertTrue(PortalLog::query()
            ->where('employee_id', $analyst->getKey())
            ->where('resource', 'bug-reports')
            ->where('message', 'like', '%marked the bug report Calendar skips Sunday Fixed')
            ->exists());

        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['note' => ''])->assertOk()->assertJsonPath('data.note', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'closed'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose New, In progress, In review, Fixed or Cancelled.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, [])
            ->assertStatus(422)->assertJsonPath('message', 'Change its kind, status, note, who handles it, its dates, parent, team or version.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/999999999', ['status' => 'fixed'])->assertNotFound();
        $this->withHeaders($engineering)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'new'])->assertForbidden();
    }

    // The reported ask (2026-10-13): who in MIS handles a report, and the semantic version its fix needs.
    public function test_mis_assigns_a_report_or_takes_it_and_records_its_version(): void
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Export drops the last row',
            'description' => 'The spreadsheet ends one row short.',
            'status' => 'new',
            'reported_on' => '2026-10-12',
        ]);
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $engineer = $this->memberOf('Engineering');

        // Only active MIS members are offered, and only they can be given it.
        $assignees = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('assignees'));
        $this->assertTrue($assignees->contains('id', (string) $analyst->getKey()));
        $this->assertFalse($assignees->contains('id', (string) $engineer->getKey()));
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $engineer->getKey()])
            ->assertStatus(422)->assertJsonPath('message', 'Choose someone in MIS to handle it.');

        // Taking it is assigning it to yourself, and the log says so.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $analyst->getKey()])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', (string) $analyst->getKey());
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%took the bug report Export drops the last row')->exists());
        $colleague = $assignees->first(fn (array $person): bool => $person['id'] !== (string) $analyst->getKey());
        $this->assertNotNull($colleague, 'No second MIS member to assign.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => $colleague['id']])
            ->assertOk()
            ->assertJsonPath('data.assignee.name', $colleague['name']);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%assigned the bug report Export drops the last row to '.$colleague['name'])->exists());

        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => 'minor', 'fixedIn' => ' 1.4.2 '])
            ->assertOk()
            ->assertJsonPath('data.versionLevel', 'minor')
            ->assertJsonPath('data.fixedIn', '1.4.2');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => 'huge'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose Major, Minor or Patch.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['fixedIn' => 'v1.4'])
            ->assertStatus(422)->assertJsonPath('message', 'Write the version as X.Y.Z, like 1.4.2.');

        // Cleared like any field, and then let go; once nobody handles it, it changes no further.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['versionLevel' => '', 'fixedIn' => ''])
            ->assertOk()
            ->assertJsonPath('data.versionLevel', null)
            ->assertJsonPath('data.fixedIn', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => null])
            ->assertOk()
            ->assertJsonPath('data.assignee', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => null, 'versionLevel' => 'patch'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('message', 'like', '%unassigned the bug report Export drops the last row')->exists());
    }

    // The reported ask (2026-10-19): the button also takes feature requests and feedback, and MIS hears of each by its kind.
    public function test_a_feature_request_is_filed_as_one_and_mis_hears_of_it_as_one(): void
    {
        $headers = $this->headersFor($this->memberOf('Engineering'));
        $mis = $this->headersFor($this->memberOf('MIS'));

        $id = $this->withHeaders($headers)->post(self::BASE.'bug-reports', [
            'kind' => 'feature',
            'title' => 'Export the map',
            'description' => 'A PDF of the team map for the weekly meeting.',
        ])->assertCreated()->json('data.id');

        $listed = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('feature', $listed['kind']);
        $notice = PortalNotification::query()->where('type', 'bug-reports.filed')->get()
            ->first(fn (PortalNotification $notice): bool => ($notice->payload['recordId'] ?? null) === $id);
        $this->assertNotNull($notice);
        $this->assertSame('New feature request', $notice->title);
        $this->assertSame('"Export the map" was requested.', $notice->message);
    }

    // The reported ask (2026-10-19): a bug that is really a feature request is filed as one, like any change, once handled.
    public function test_mis_files_a_report_under_another_kind_and_the_log_says_so(): void
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Dark mode for the calendar',
            'description' => 'The calendar stays white at night.',
            'status' => 'new',
            'reported_on' => '2026-10-19',
        ]);
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);

        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'feature'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'feedback', 'assigneeId' => (string) $analyst->getKey()])
            ->assertOk()->assertJsonPath('data.kind', 'feedback');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'feature'])
            ->assertOk()->assertJsonPath('data.kind', 'feature');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => ''])
            ->assertStatus(422)->assertJsonPath('message', 'Choose Bug, Feature request or Feedback.');
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])->assertOk();
        // In review (2026-10-20, user): on staging, being tested.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_review'])
            ->assertOk()->assertJsonPath('data.status', 'in_review');

        $this->assertSame('feature', DB::connection('portal')->table('bug_reports')->where('id', $id)->value('kind'));
        $messages = PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->where('record_id', (string) $id)->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, 'filed the feedback Dark mode for the calendar as a feature request')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, 'marked the feature request Dark mode for the calendar In progress')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, 'marked the feature request Dark mode for the calendar In review')));
    }

    // Triage (2026-10-21, user): a cancelled one says why - won't fix, or a duplicate of the report it names.
    public function test_mis_cancels_a_report_as_wont_fix_or_as_a_duplicate_and_the_log_says_which(): void
    {
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $original = $this->unlinked('Calendar skips Sunday');
        $id = $this->unlinked('The month view jumps', ['assignee_id' => $analyst->getKey()]);

        // An app from before sends only the status: it is cancelled as won't fix.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'wont_fix'])
            ->assertOk()->assertJsonPath('data.status', 'wont_fix')->assertJsonPath('data.cancelReason', 'wont_fix')->assertJsonPath('data.duplicateOf', null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['cancelReason' => 'duplicate', 'duplicateOf' => (string) $original])
            ->assertOk()->assertJsonPath('data.cancelReason', 'duplicate')
            ->assertJsonPath('data.duplicateOf', ['id' => (string) $original, 'title' => 'Calendar skips Sunday']);
        $listed = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame(['id' => (string) $original, 'title' => 'Calendar skips Sunday'], $listed['duplicateOf']);
        // Moved on, it is cancelled no longer and gives no reason.
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.cancelReason', null)->assertJsonPath('data.duplicateOf', null);

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame([null, null], [$row->cancel_reason, $row->duplicate_of]);
        $messages = PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->where('record_id', (string) $id)->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, "cancelled the bug report The month view jumps as won't fix")));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, 'marked the bug report The month view jumps a duplicate of #'.$original.' Calendar skips Sunday')));
    }

    public function test_triage_that_does_not_add_up_is_refused_and_changes_nothing(): void
    {
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $original = $this->unlinked('Calendar skips Sunday');
        $duplicate = $this->unlinked('Calendar skips Sunday again', ['status' => 'wont_fix', 'cancel_reason' => 'duplicate', 'duplicate_of' => $original]);
        $id = $this->unlinked('The month view jumps', ['assignee_id' => $analyst->getKey()]);
        $patch = fn (array $body) => $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, $body)->assertStatus(422);

        $patch(['status' => 'wont_fix', 'cancelReason' => 'later'])->assertJsonPath('message', "Choose Won't fix or Duplicate.");
        $patch(['cancelReason' => 'wont_fix'])->assertJsonPath('message', 'Only a cancelled one has a reason: set its status to Cancelled too.');
        $patch(['status' => 'wont_fix', 'cancelReason' => 'duplicate'])->assertJsonPath('message', 'Choose the issue or request it duplicates.');
        $patch(['status' => 'wont_fix', 'cancelReason' => 'duplicate', 'duplicateOf' => (string) $id])->assertJsonPath('message', 'It cannot be a duplicate of itself.');
        $patch(['status' => 'wont_fix', 'cancelReason' => 'duplicate', 'duplicateOf' => '999999999'])->assertJsonPath('message', 'There is no #999999999 to be a duplicate of.');
        // One in the Recycle Bin is not there to be duplicated.
        $binned = $this->unlinked('Calendar skips Monday', ['deleted_at' => '2026-10-21 01:00:00']);
        $patch(['status' => 'wont_fix', 'cancelReason' => 'duplicate', 'duplicateOf' => (string) $binned])->assertJsonPath('message', 'There is no #'.$binned.' to be a duplicate of.');
        $patch(['status' => 'wont_fix', 'cancelReason' => 'duplicate', 'duplicateOf' => (string) $duplicate])
            ->assertJsonPath('message', '#'.$duplicate.' is a duplicate itself: choose #'.$original.', the one it duplicates.');
        $patch(['status' => 'wont_fix', 'cancelReason' => 'wont_fix', 'duplicateOf' => (string) $original])->assertJsonPath('message', 'Only a duplicate names the one it duplicates.');

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['new', null, null], [$row->status, $row->cancel_reason, $row->duplicate_of]);
    }

    // The Access page names the rule as the sidebar does, and an MIS member holds Send to Jira beneath it (2026-10-19).
    public function test_the_access_page_names_the_rule_issues_and_requests_with_send_to_jira_beneath(): void
    {
        $mis = $this->headersFor($this->memberOf('MIS'));

        $powers = collect($this->withHeaders($mis)->getJson(self::BASE.'access')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame('Issues & Requests', $powers['bug-reports']['label']);
        $this->assertSame('Send to Jira', $powers['bug-reports.jira']['label']);
        $this->assertSame('bug-reports', $powers['bug-reports.jira']['parentKey']);
        $this->assertSame('Delete issues and requests', $powers['bug-reports.delete']['label']);
        $this->assertSame('bug-reports', $powers['bug-reports.delete']['parentKey']);
    }

    // Archive (2026-10-21, user): out of the way behind a filter, frozen there, and back as it was.
    public function test_mis_archives_a_report_out_of_the_list_and_brings_it_back(): void
    {
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $id = $this->unlinked('The month view jumps', ['assignee_id' => $analyst->getKey()]);
        $listed = fn (string $query = ''): ?array => collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports'.$query)->assertOk()->json('data'))
            ->firstWhere('id', (string) $id);
        $archive = fn (mixed $archived) => $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => $archived]);

        $archive('yes')->assertStatus(422)->assertJsonPath('message', 'Say whether to archive it or bring it back.');
        $archive(true)->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.archivedBy.id', (string) $analyst->getKey());
        // Asked again, it already is: nothing changes and nothing more is logged.
        $archive(true)->assertOk();

        // An app from before never asks for archived ones, so it never meets one.
        $this->assertNull($listed());
        $this->assertNotNull($listed('?withArchived=1')['archivedAt'] ?? null);
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id, ['note' => 'Later.'])
            ->assertStatus(409)->assertJsonPath('message', 'It is archived: unarchive it to change it.');
        $this->withHeaders($mis)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertStatus(409)->assertJsonPath('message', 'It is archived: unarchive it to change it.');

        $archive(false)->assertOk()->assertJsonPath('data.archivedAt', null)->assertJsonPath('data.archivedBy', null);
        $this->assertNotNull($listed());
        $messages = PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->where('record_id', (string) $id)->pluck('message');
        $this->assertCount(1, $messages->filter(fn (string $message): bool => str_ends_with($message, ' archived the bug report The month view jumps')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, ' unarchived the bug report The month view jumps')));
    }

    // Delete (2026-10-21, user): into the deleter's Recycle Bin with only what its row shows, and back from there.
    public function test_mis_deletes_a_report_to_the_recycle_bin_and_restores_it_from_there(): void
    {
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $id = $this->unlinked('The month view jumps', [
            'kind' => 'feature', 'page' => 'Calendar', 'note' => 'Ask Jamie', 'archived_at' => '2026-10-21 01:00:00', 'archived_by' => $analyst->getKey(),
        ]);
        DB::connection('portal')->table('bug_report_images')->insert([
            'bug_report_id' => $id, 'file_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/bug-reports/shot.png', 'mime_type' => 'image/png', 'size_bytes' => 9,
        ]);
        $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->deleteJson(self::BASE.'bug-reports/'.$id)->assertForbidden();

        $this->withHeaders($mis)->deleteJson(self::BASE.'bug-reports/'.$id)->assertNoContent();
        // A retried delete answers as the first did, and the bin holds it once.
        $this->withHeaders($mis)->deleteJson(self::BASE.'bug-reports/'.$id)->assertNoContent();

        $this->assertNotContains((string) $id, collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports?withArchived=1')->json('data'))->pluck('id')->all());
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => false])->assertNotFound();
        $this->assertSame(1, DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $id)->count());
        $bin = Recycle::query()->where('product', 'portal')->where('recycle_key', 'bug-report-'.$id)->get();
        $this->assertCount(1, $bin);
        $this->assertSame(['bug-reports', (string) $id, (int) $analyst->getKey()], [$bin[0]->resource, (string) $bin[0]->record_id, (int) $bin[0]->deleted_by]);
        // What its row shows, and never the description, the note, the sender or where the screenshots are. A JSON
        // column keeps no key order, so both are sorted.
        $payload = $bin[0]->payload;
        ksort($payload);
        ksort($payload['issue']);
        $this->assertSame([
            'id' => (string) $id,
            'issue' => ['images' => 1, 'jiraCancelled' => false, 'jiraKey' => null, 'kind' => 'feature', 'page' => 'Calendar', 'reportedOn' => '2026-10-21', 'status' => 'new'],
            'kind' => 'issue', 'submittedOn' => '2026-10-21', 'title' => 'The month view jumps',
        ], $payload);

        $inBin = collect($this->withHeaders($mis)->getJson(self::BASE.'administration/recycle-bin?type=issue')->assertOk()->json('data'))->firstWhere('recordId', (string) $id);
        $this->assertSame(['issue', 'The month view jumps'], [$inBin['type'] ?? null, $inBin['title'] ?? null]);
        $this->withHeaders($mis)->getJson(self::BASE.'administration/recycle-bin/'.$bin[0]->id)->assertOk()
            ->assertJsonPath('data.issue', ['kind' => 'feature', 'status' => 'new', 'reportedOn' => '2026-10-21', 'page' => 'Calendar', 'jiraKey' => null, 'jiraCancelled' => false, 'images' => 1])
            ->assertJsonPath('data.project', null);

        $this->withHeaders($mis)->postJson(self::BASE.'administration/recycle-bin/'.$bin[0]->id.'/restore')->assertNoContent();
        // Back as it was: archived still.
        $restored = collect($this->withHeaders($mis)->getJson(self::BASE.'bug-reports?withArchived=1')->json('data'))->firstWhere('id', (string) $id);
        $this->assertNotNull($restored['archivedAt'] ?? null);
        $this->assertFalse(Recycle::query()->where('product', 'portal')->where('recycle_key', 'bug-report-'.$id)->exists());
        $messages = PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->where('record_id', (string) $id)->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, ' deleted the feature request The month view jumps')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, ' restored the feature request The month view jumps')));
    }

    // Deleting is a power of its own (2026-10-21, user), switched off per person while the page stays theirs.
    public function test_deleting_needs_delete_issues_and_requests_and_archiving_does_not(): void
    {
        $mis = $this->headersFor($this->memberOf('MIS'));
        $id = $this->unlinked('The month view jumps');
        $this->withHeaders($mis)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.delete', 'on' => false])->assertOk();

        $this->withHeaders($mis)->deleteJson(self::BASE.'bug-reports/'.$id)->assertForbidden();
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => true])->assertOk();
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('deleted_at'));
    }

    /**
     * A report in no Jira, made here rather than sent.
     *
     * @param  array<string, mixed>  $columns
     */
    private function unlinked(string $title, array $columns = []): int
    {
        return (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => $title,
            'description' => 'It happens every month.',
            'status' => 'new',
            'reported_on' => '2026-10-21',
            ...$columns,
        ]);
    }

    // An active ordinary member of the department; when the database has none -- its members all admins -- one of
    // them is made one for this test alone, inside its transaction (2026-10-22).
    private function memberOf(string $department): Employee
    {
        $members = fn () => Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw('LOWER(TRIM(department)) = ?', [strtolower($department)])
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '');
        $employee = $members()->whereRaw("LOWER(COALESCE(role, '')) = 'user'")->first();
        $any = $employee === null ? $members()->first() : null;
        if ($any !== null) {
            DB::connection('portal')->table('employees')->where('id', $any->getKey())->update(['role' => 'User']);
            $employee = $any->fresh();
        }
        $this->assertNotNull($employee, "No active {$department} member.");

        return $employee;
    }
}
