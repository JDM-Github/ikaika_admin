<?php

namespace Tests\Feature;

use App\Jobs\SendBugReportToJira;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

// Issues & Requests in Jira (2026-10-19, user): MIS sends a report by hand or tries it again, the switch, and what Jira owns.
class PortalBugReportsJiraTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/';

    private const SITE = 'https://ikaika-test.atlassian.net';

    private const SHOT = 'https://res.cloudinary.com/test-cloud/image/upload/q_auto/v1/ikaika-portal/bug-reports/';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DB::connection('core')->table('settings')->where('product', 'portal')->where('setting_key', 'jira.auto_send')->delete();
    }

    public function test_mis_sends_a_feature_request_and_jira_gets_what_it_says_and_no_names(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $id = $this->report(['kind' => 'feature', 'note' => 'Ask Jamie about the layout', 'assignee_id' => $analyst->getKey()], ['one.png' => 'image/png', 'two.jpg' => 'image/jpeg']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [
                ['id' => '10001', 'name' => 'Bug', 'subtask' => false],
                ['id' => '10003', 'name' => 'Task', 'subtask' => false],
                ['id' => '10009', 'name' => 'Sub-task', 'subtask' => true],
            ]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201),
            self::SITE.'/rest/api/3/issue/10042/attachments' => Http::response([['id' => '1']]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
            self::SHOT.'*' => Http::response('png-bytes'),
        ]);

        $this->withHeaders($this->headersFor($analyst))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.jira.state', 'sent')
            ->assertJsonPath('data.jira.key', 'IKP-12')
            ->assertJsonPath('data.jira.url', self::SITE.'/browse/IKP-12')
            ->assertJsonPath('data.jira.status', 'To Do');

        // A feature request goes as Story when the project has one, and as Task when it does not.
        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0];
        $this->assertSame('10003', $created['fields']['issuetype']['id']);
        $this->assertSame(['ikaika-portal'], $created['fields']['labels']);
        $this->assertStringNotContainsString('Jamie', $created->body());
        $this->assertStringNotContainsString((string) $analyst->first_name, $created->body());
        $attached = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/attachments'))->map(fn (array $pair): Request => $pair[0])->values();
        $this->assertCount(2, $attached);
        $this->assertTrue($attached->every(fn (Request $request): bool => $request->hasHeader('X-Atlassian-Token', 'no-check')));
        $this->assertTrue($attached[0]->hasFile('file', 'png-bytes', 'screenshot-1.png'));
        $this->assertTrue($attached[1]->hasFile('file', 'png-bytes', 'screenshot-2.jpg'));

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame('10042', $row->jira_issue_id);
        $this->assertSame(2, (int) $row->jira_attached);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('record_id', (string) $id)
            ->where('message', 'like', '%sent the feature request Export the map to Jira as IKP-12')->exists());
    }

    public function test_a_screenshot_jira_refused_is_sent_again_without_making_the_issue_twice(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report([], ['one.png' => 'image/png', 'two.png' => 'image/png']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201),
            self::SITE.'/rest/api/3/issue/10042/attachments' => Http::sequence()
                ->push([['id' => '1']])
                ->push(['errorMessages' => ['The attachment is too large.']], 413)
                ->push([['id' => '2']]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
            self::SHOT.'*' => Http::response('png-bytes'),
        ]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.jira.state', 'failed')
            ->assertJsonPath('data.jira.key', 'IKP-12')
            ->assertJsonPath('data.jira.error', 'Screenshot 2 could not be attached: Jira HTTP 413: The attachment is too large.');
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.jira.state', 'sent');

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue'));
        $this->assertCount(3, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/attachments')));
        $this->assertSame(2, (int) DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_attached'));
    }

    public function test_a_refused_account_fails_the_send_without_spending_one_of_its_tries(): void
    {
        $this->connectJira();
        $headers = $this->headersFor($this->memberOf('MIS'));
        $id = $this->report();
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401)]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.jira.state', 'failed')
            ->assertJsonPath('data.jira.error', 'Jira HTTP 401: Client must be authenticated to access this resource.');

        $this->assertSame(0, (int) DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_attempts'));
        $this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->assertJsonPath('jira.connection', 'refused');
    }

    public function test_sending_needs_jira_connected_and_is_refused_while_it_is_on_its_way(): void
    {
        $headers = $this->headersFor($this->memberOf('MIS'));
        $id = $this->report();
        Http::preventStrayRequests();

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertStatus(503)->assertJsonPath('message', 'Jira is not connected yet.');
        $this->connectJira();
        Cache::lock('portal-jira-push:'.$id, 120)->get();
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertConflict()->assertJsonPath('message', 'It is being sent right now.');
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/999999999/jira')->assertNotFound();

        Http::assertNothingSent();
    }

    // Sync (2026-10-20, user): one already in Jira is read back, and one whose issue was deleted is made anew.
    public function test_syncing_a_report_in_jira_reads_it_back_or_makes_its_deleted_issue_again(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $there = $this->report(['jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent', 'jira_status' => 'To Do']);
        $gone = $this->report(['jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue/10050*' => Http::response(['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => ['name' => 'In Review', 'statusCategory' => ['key' => 'indeterminate']]]]),
            self::SITE.'/rest/api/3/issue/10051*' => Http::response(['errorMessages' => ['Issue does not exist or you do not have permission to see it.']], 404),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10090', 'key' => 'IKP-30'], 201),
            self::SITE.'/rest/api/3/issue/10090*' => Http::response(['id' => '10090', 'key' => 'IKP-30', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$there.'/jira')
            ->assertOk()->assertJsonPath('data.jira.key', 'IKP-20')->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.jira.status', 'In Review');
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$gone.'/jira')
            ->assertOk()->assertJsonPath('data.jira.state', 'sent')->assertJsonPath('data.jira.key', 'IKP-30');

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::SITE.'/rest/api/3/issue'));
        // The log names the issue the sync made, not the one it only read back.
        $messages = PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_ends_with($message, 'to Jira as IKP-30')));
        $this->assertFalse($messages->contains(fn (string $message): bool => str_ends_with($message, 'to Jira as IKP-20')));
    }

    public function test_only_who_holds_send_to_jira_sends_one_or_turns_the_switch_403(): void
    {
        $this->connectJira();
        $id = $this->report();
        $analyst = $this->memberOf('MIS');
        $mis = $this->headersFor($analyst);
        $engineering = $this->headersFor($this->memberOf('Engineering'));
        Http::preventStrayRequests();

        $this->withHeaders($engineering)->postJson(self::BASE.'bug-reports/'.$id.'/jira')->assertForbidden();
        $this->withHeaders($engineering)->patchJson(self::BASE.'bug-reports/jira', ['autoSend' => false])->assertForbidden();
        // An MIS member who switched Send to Jira off still reads the page, but sends nothing.
        $this->withHeaders($mis)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($mis)->postJson(self::BASE.'bug-reports/'.$id.'/jira')->assertForbidden();
        $this->withHeaders($mis)->patchJson(self::BASE.'bug-reports/jira', ['autoSend' => false])->assertForbidden();
        $this->withHeaders($mis)->getJson(self::BASE.'bug-reports')->assertOk();

        Http::assertNothingSent();
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_state'));
    }

    public function test_mis_turns_sending_new_ones_off_and_the_page_and_the_log_say_so(): void
    {
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['autoSend' => false])
            ->assertOk()->assertJsonPath('data.autoSend', false)->assertJsonPath('data.connection', 'not_configured');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['autoSend' => 'off'])
            ->assertStatus(422)->assertJsonPath('message', 'Say whether new ones go to Jira on their own.');

        $this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->assertJsonPath('jira.autoSend', false);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())
            ->where('message', 'like', '%turned sending new issues and requests to Jira off')->exists());
    }

    // Both ways (2026-10-20, user): MIS changing a linked report's kind or status changes the issue first.
    public function test_mis_changes_a_linked_reports_kind_and_status_in_jira_first(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [
                ['id' => '10001', 'name' => 'Bug'], ['id' => '10002', 'name' => 'Story'], ['id' => '10003', 'name' => 'Task'],
            ]]),
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => $this->boardMoves()]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => [
                'status' => ['name' => 'Done', 'statusCategory' => ['key' => 'indeterminate']],
                'issuetype' => ['name' => 'Story'],
            ]]),
        ]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed', 'kind' => 'feature'])
            ->assertOk()
            ->assertJsonPath('data.status', 'fixed')
            ->assertJsonPath('data.kind', 'feature')
            ->assertJsonPath('data.jira.status', 'Done');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['fields'] === ['issuetype' => ['id' => '10002']]);
        // IPS's own board: Done sits under "in progress", and its name still picks it.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['transition'] === ['id' => '41']);
        // Fixed in has no field on IPS's screens, so it stays the portal's and nothing goes to Jira for it.
        $sent = count(Http::recorded());
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['fixedIn' => '1.4.2', 'note' => 'Shipped.'])
            ->assertOk()->assertJsonPath('data.fixedIn', '1.4.2');
        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_change_jira_refuses_leaves_the_report_as_it_was_and_needs_send_to_jira(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task']]]),
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => [$this->boardMoves()[0]]]),
            self::SITE.'/rest/api/3/issue/10042' => Http::response(['errors' => ['issuetype' => 'The issue type selected is invalid.']], 400),
        ]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_review'])
            ->assertStatus(422)->assertJsonPath('message', 'Jira did not take the change: Jira has no way to move IKP-7 to In review from where it is.');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'feedback'])
            ->assertStatus(422)->assertJsonPath('message', 'Jira did not take the change: Jira HTTP 400: issuetype: The issue type selected is invalid.');
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['new', 'bug'], [$row->status, $row->kind]);

        // Without Send to Jira the issue is not theirs to change; the portal's own fields still are.
        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $before = count(Http::recorded());
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertForbidden()->assertJsonPath('message', 'Its kind and status change IKP-7 in Jira too, which needs Send to Jira.');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['note' => 'Waiting on Jira.', 'versionLevel' => 'patch'])
            ->assertOk()->assertJsonPath('data.note', 'Waiting on Jira.');
        $this->assertCount($before, Http::recorded());

        // An issue Jira no longer has leaves the report the portal's alone again.
        DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['jira_state' => 'deleted']);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertOk()->assertJsonPath('data.status', 'fixed')->assertJsonPath('data.jira.state', 'deleted');
    }

    // Read back (2026-10-20, user): a work type changed in Jira changes the kind, and Fixed in stays without a fix version.
    public function test_jiras_work_type_comes_back_as_the_kind_and_the_portals_fixed_in_stays(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $feedback = $this->report(['kind' => 'feedback', 'fixed_in' => '1.4.2', 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent']);
        // No Story in the project, so the feature request went as Task; Task alone does not make it feedback.
        $feature = $this->report(['kind' => 'feature', 'jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', 'jira_state' => 'sent']);
        $done = ['name' => 'Done', 'statusCategory' => ['key' => 'done']];
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task']]]),
            self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
                ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => $done, 'issuetype' => ['name' => 'Bug']]],
                ['id' => '10051', 'key' => 'IKP-21', 'fields' => ['status' => $done, 'issuetype' => ['name' => 'Task']]],
            ], 'issueErrors' => []]),
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$feedback, $feature])->get()->keyBy('id');
        $this->assertSame(['bug', 'fixed', '1.4.2'], [$rows[$feedback]->kind, $rows[$feedback]->status, $rows[$feedback]->fixed_in]);
        $this->assertSame('feature', $rows[$feature]->kind);
    }

    // Production had reports from before Jira (2026-10-20, user): every one not there yet goes at once.
    public function test_mis_sends_every_report_not_in_jira_yet_at_once(): void
    {
        Queue::fake();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/send-all')->assertStatus(503);
        $this->connectJira();
        // Only this test's reports: the database may hold others from a copied server.
        DB::connection('portal')->table('bug_reports')->update(['jira_state' => 'sent']);
        $never = $this->report();
        $failed = $this->report(['jira_state' => 'failed', 'jira_attempts' => 5, 'jira_error' => 'Jira HTTP 401']);
        $kept = [
            $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20']),
            $this->report(['jira_state' => 'deleted', 'jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21']),
        ];

        $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->postJson(self::BASE.'bug-reports/jira/send-all')->assertForbidden();
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/send-all')
            ->assertOk()->assertJsonPath('queued', 2)->assertJsonPath('jira.projectKey', 'IKP');

        Queue::assertPushed(SendBugReportToJira::class, 2);
        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$never, $failed, ...$kept])->get()->keyBy('id');
        $this->assertSame(['pending', 'pending', 'sent', 'deleted'], [$rows[$never]->jira_state, $rows[$failed]->jira_state, $rows[$kept[0]]->jira_state, $rows[$kept[1]]->jira_state]);
        $this->assertSame([0, null], [(int) $rows[$failed]->jira_attempts, $rows[$failed]->jira_error]);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('resource', 'bug-reports')->pluck('message')
            ->contains(fn (string $message): bool => str_ends_with($message, 'sent 2 issues and requests to Jira')));
    }

    /**
     * IPS's moves: anywhere from anywhere, its Done filed under "in progress" and Cancelled under "done".
     *
     * @return list<array<string, mixed>>
     */
    private function boardMoves(): array
    {
        return [
            ['id' => '11', 'name' => 'To Do', 'to' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]],
            ['id' => '21', 'name' => 'In Progress', 'to' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']]],
            ['id' => '31', 'name' => 'In Review', 'to' => ['name' => 'In Review', 'statusCategory' => ['key' => 'indeterminate']]],
            ['id' => '41', 'name' => 'Done', 'to' => ['name' => 'Done', 'statusCategory' => ['key' => 'indeterminate']]],
            ['id' => '51', 'name' => 'Cancelled', 'to' => ['name' => 'Cancelled', 'statusCategory' => ['key' => 'done']]],
        ];
    }

    // The reported ask (2026-10-19): every new one goes to Jira as it comes in, and submitting never waits on Jira.
    public function test_a_new_report_is_queued_for_jira_as_it_comes_in(): void
    {
        $this->connectJira();
        Queue::fake([SendBugReportToJira::class]);
        Http::preventStrayRequests();

        $id = $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->post(self::BASE.'bug-reports', [
            'kind' => 'feedback', 'title' => 'Love the map', 'description' => 'It saves a call every morning.',
        ])->assertCreated()->json('data.id');

        $this->assertSame('pending', DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_state'));
        Queue::assertPushed(SendBugReportToJira::class, fn (SendBugReportToJira $job): bool => $job->reportId === (int) $id);
        Http::assertNothingSent();
    }

    public function test_with_sending_turned_off_a_new_report_stays_out_of_jira(): void
    {
        $this->connectJira();
        DB::connection('core')->table('settings')->insert(['product' => 'portal', 'setting_key' => 'jira.auto_send', 'setting_value' => '0']);
        Queue::fake([SendBugReportToJira::class]);

        $id = $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->post(self::BASE.'bug-reports', [
            'title' => 'Save does nothing', 'description' => 'The form stays open.',
        ])->assertCreated()->json('data.id');

        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_state'));
        Queue::assertNotPushed(SendBugReportToJira::class);
    }

    // On the sync queue the job runs as it is pushed: the whole way from the corner button to a Jira issue.
    public function test_a_new_report_becomes_a_jira_issue_once_its_job_runs(): void
    {
        $this->connectJira();
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => []]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10077', 'key' => 'IKP-30'], 201),
            self::SITE.'/rest/api/3/issue/10077*' => Http::response(['id' => '10077', 'key' => 'IKP-30', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $id = $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->post(self::BASE.'bug-reports', [
            'title' => 'Save does nothing', 'description' => 'The form stays open.',
        ])->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame('sent', $row->jira_state);
        $this->assertSame('IKP-30', $row->jira_issue_key);
    }

    // Refresh (2026-10-19, user): a move on the Jira board shows at once, not at the next webhook or quarter hour.
    public function test_refresh_reads_every_linked_report_back_from_jira_and_returns_the_list(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $id = $this->report(['jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent', 'jira_status' => 'To Do']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']]]],
        ], 'issueErrors' => []])]);

        $response = $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame('in_progress', $row['status']);
        $this->assertSame('In Progress', $row['jira']['status']);
        $response->assertJsonPath('jira.connection', 'ok');
    }

    public function test_refresh_still_lists_without_jira_or_when_jira_refuses_and_is_only_for_who_sees_the_list(): void
    {
        $headers = $this->headersFor($this->memberOf('MIS'));
        $this->report(['jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent']);
        Http::preventStrayRequests();

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk()->assertJsonPath('jira.connection', 'not_configured');
        Http::assertNothingSent();
        $this->connectJira();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401)]);
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk()
            ->assertJsonPath('jira.connection', 'refused')
            ->assertJsonPath('jira.connectionMessage', 'Jira HTTP 401: Client must be authenticated to access this resource.');
        $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->postJson(self::BASE.'bug-reports/jira/sync')->assertForbidden();
    }

    // One label now (2026-10-20, user): a try whose create went unanswered finds its issue by the description.
    public function test_a_retry_finds_the_issue_an_unanswered_create_made_by_the_report_number_it_carries(): void
    {
        $this->connectJira();
        $id = $this->report(['jira_state' => 'failed', 'jira_attempts' => 1]);
        $said = fn (string $mark): array => ['type' => 'doc', 'version' => 1, 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sent anonymously from the IKAIKA portal '.$mark.'.']]],
        ]];
        Http::preventStrayRequests();
        Http::fake([
            // Jira matches words, so another report's issue can come back too; only the exact mark is taken.
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => [
                ['id' => '10060', 'key' => 'IKP-21', 'fields' => ['description' => $said('(report #'.$id.'0)')]],
                ['id' => '10061', 'key' => 'IKP-22', 'fields' => ['description' => $said('(report #'.$id.')')]],
            ]]),
            self::SITE.'/rest/api/3/issue/10061*' => Http::response(['id' => '10061', 'key' => 'IKP-22', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()->assertJsonPath('data.jira.key', 'IKP-22');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/rest/api/3/issue'));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'description ~ "\"report '.$id.'\""')
            && str_contains(urldecode($request->url()), 'labels = "ikaika-portal"'));
    }

    private function connectJira(): void
    {
        config(['services.jira' => [
            ...config('services.jira'),
            'base_url' => self::SITE,
            'email' => 'svc@ikaika.test',
            'token' => 'secret-token',
            'project' => 'IKP',
        ]]);
    }

    /**
     * A report, with its screenshots named as stored.
     *
     * @param  array<string, mixed>  $columns
     * @param  array<string, string>  $shots
     */
    private function report(array $columns = [], array $shots = []): int
    {
        $id = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'kind' => 'bug',
            'title' => 'Export the map',
            'description' => 'A PDF of the team map.',
            'page' => 'Administration / Team Map',
            'status' => 'new',
            'reported_on' => '2026-10-19',
            ...$columns,
        ]);
        foreach ($shots as $name => $mime) {
            DB::connection('portal')->table('bug_report_images')->insert([
                'bug_report_id' => $id,
                'file_url' => self::SHOT.$name,
                'mime_type' => $mime,
                'size_bytes' => 9,
            ]);
        }

        return $id;
    }

    // An active ordinary member of the department.
    private function memberOf(string $department): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw('LOWER(TRIM(department)) = ?', [strtolower($department)])
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee, "No active {$department} member.");

        return $employee;
    }
}
