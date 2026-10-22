<?php

namespace Tests\Feature;

use App\Jobs\SendBugReportToJira;
use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Portal\PortalBugReportsJira;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    private const CANCELLED = ['name' => 'Cancelled', 'statusCategory' => ['key' => 'done']];

    private const TO_DO = ['name' => 'To Do', 'statusCategory' => ['key' => 'new']];

    private const DONE = ['name' => 'Done', 'statusCategory' => ['key' => 'indeterminate']];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DB::connection('core')->table('settings')->where('product', 'portal')->whereIn('setting_key', ['jira.auto_send', 'jira.sync_fields'])->delete();
        // As production, which files as it always has and follows the reports from before a channel was kept
        // (2026-10-21, user); the staging tests below say so. The routes stay under the test's own channel.
        config(['products.channel' => 'production']);
    }

    public function test_mis_sends_a_feature_request_and_jira_gets_what_it_says_and_no_names(): void
    {
        $this->syncFieldsOn();
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
            self::SITE.'/rest/api/3/user/search*' => Http::response([['accountId' => '712020:analyst', 'accountType' => 'atlassian', 'active' => true]]),
            self::SHOT.'*' => Http::response('png-bytes'),
        ]);

        $this->withHeaders($this->headersFor($analyst))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.jira.state', 'sent')
            ->assertJsonPath('data.jira.key', 'IKP-12')
            ->assertJsonPath('data.jira.url', self::SITE.'/browse/IKP-12')
            ->assertJsonPath('data.jira.status', 'To Do')
            ->assertJsonPath('data.jiraAssignee', ['state' => 'same', 'name' => null]);

        // A feature request goes as Story when the project has one, and as Task when it does not.
        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0];
        $this->assertSame('10003', $created['fields']['issuetype']['id']);
        $this->assertSame(['ikaika-portal'], $created['fields']['labels']);
        // Who handles it goes as their Jira account alone (2026-10-22, user): never their name or address.
        $this->assertSame(['accountId' => '712020:analyst'], $created['fields']['assignee']);
        $this->assertStringNotContainsString('Jamie', $created->body());
        $this->assertStringNotContainsString((string) $analyst->first_name, $created->body());
        $this->assertStringNotContainsString(strtolower((string) $analyst->email), strtolower($created->body()));
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

    // Named by choice (2026-10-20, user): the issue says who sent it, under a summary named as the KAN board's are.
    public function test_a_named_senders_issue_says_who_sent_it(): void
    {
        $this->connectJira();
        $sender = $this->memberOf('Engineering');
        $id = $this->report(['title' => 'calendar skips sunday', 'reporter_id' => $sender->getKey()]);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')->assertOk();

        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0];
        $this->assertSame('IP-Calendar-Skips-Sunday', $created['fields']['summary']);
        $this->assertStringContainsString(
            'Sent from the IKAIKA portal by '.PortalSubmittedReportPresenter::memberName($sender->first_name, $sender->last_name).' (report #'.$id.').',
            $created->body(),
        );
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
            ->assertStatus(422)->assertJsonPath('message', 'Say whether new ones go to Jira on their own, or whether their fields are kept in step with Jira.');

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

    // A report's plan (2026-10-22, user): a new issue carries its dates and epic, and goes to the default team, as far
    // as its create screen takes them; the report then names the team too.
    public function test_a_new_issue_carries_its_plan_and_goes_to_the_default_team(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        config(['services.jira.default_team' => 'dev']);
        $bug = $this->report(['start_on' => '2026-10-23', 'due_on' => '2026-10-30', 'parent_key' => 'IKP-1', 'parent_summary' => 'Payroll revamp']);
        $feedback = $this->report(['kind' => 'feedback', 'start_on' => '2026-10-23', 'team_id' => '9999-ops', 'team_name' => 'Ops']);
        $planned = ['status' => self::TO_DO, 'parent' => ['key' => 'IKP-1', 'fields' => ['summary' => 'Payroll revamp']], 'duedate' => '2026-10-30',
            'customfield_10015' => '2026-10-23', 'customfield_10001' => ['id' => '3688-dev', 'name' => 'DEV']];
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue' => Http::sequence()->push(['id' => '10042', 'key' => 'IKP-12'], 201)->push(['id' => '10043', 'key' => 'IKP-13'], 201),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => $planned]),
            self::SITE.'/rest/api/3/issue/10043*' => Http::response(['id' => '10043', 'key' => 'IKP-13', 'fields' => ['status' => self::TO_DO, 'customfield_10001' => '9999-ops']]),
            ...$this->planStubs(),
        ]);
        $headers = $this->headersFor($this->memberOf('MIS'));

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$bug.'/jira')->assertOk()
            ->assertJsonPath('data.team', ['id' => '3688-dev', 'name' => 'DEV'])
            ->assertJsonPath('data.parent', ['key' => 'IKP-1', 'summary' => 'Payroll revamp'])
            ->assertJsonPath('data.dueOn', '2026-10-30');
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$feedback.'/jira')->assertOk()
            ->assertJsonPath('data.team', ['id' => '9999-ops', 'name' => 'Ops']);

        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->map(fn (array $pair): array => $pair[0]['fields'])->values();
        $this->assertSame(['key' => 'IKP-1'], $created[0]['parent']);
        $this->assertSame(['2026-10-30', '2026-10-23', '3688-dev'], [$created[0]['duedate'], $created[0]['customfield_10015'], $created[0]['customfield_10001']]);
        // Feedback is filed as Task, whose screen has no start date: it goes without, and with its own team.
        $this->assertArrayNotHasKey('customfield_10015', $created[1]);
        $this->assertSame('9999-ops', $created[1]['customfield_10001']);
        $this->assertSame(['2026-10-23', 'Ops'], [
            substr((string) DB::connection('portal')->table('bug_reports')->where('id', $feedback)->value('start_on'), 0, 10),
            DB::connection('portal')->table('bug_reports')->where('id', $feedback)->value('team_name'),
        ]);
    }

    // Both ways (2026-10-22, user): a linked report's plan changes in Jira first -- one edit of only what Jira holds
    // otherwise -- and a parent is cleared only on an issue that has one.
    public function test_mis_changes_a_linked_reports_plan_in_jira_first(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent', 'due_on' => '2026-10-28']);
        Http::preventStrayRequests();
        Http::fake([
            // Jira was given the 5th directly; the portal has not heard yet.
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'duedate' => '2026-11-05']]),
            ...$this->planStubs(),
        ]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['startOn' => '2026-10-23', 'dueOn' => '2026-11-05', 'teamId' => '3688-dev', 'parentKey' => 'ikp-1'])
            ->assertOk()
            ->assertJsonPath('data.startOn', '2026-10-23')
            ->assertJsonPath('data.dueOn', '2026-11-05')
            ->assertJsonPath('data.team', ['id' => '3688-dev', 'name' => 'DEV'])
            ->assertJsonPath('data.parent', ['key' => 'IKP-1', 'summary' => 'Payroll revamp']);

        $this->assertSame(['GET issue/10042', 'PUT issue/10042', 'GET issue/10042'], array_slice($this->calls(), -3));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->data() === ['fields' => ['customfield_10015' => '2026-10-23', 'customfield_10001' => '3688-dev', 'parent' => ['key' => 'IKP-1']]]);
        // Clearing a parent the issue does not have, and a due date Jira already has, needs no edit at all.
        $before = count(Http::recorded());
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['parentKey' => '', 'dueOn' => '2026-11-05'])->assertOk()->assertJsonPath('data.parent', null);
        $this->assertSame(['GET issue/10042', 'GET issue/10042'], array_slice($this->calls(), $before));
    }

    public function test_a_plan_change_jira_refuses_changes_nothing_and_needs_send_to_jira(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $unsent = $this->report(['assignee_id' => $analyst->getKey()]);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/10042' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['errors' => ['customfield_10001' => 'Field customfield_10001 is not valid.']], 400)
                : Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO]]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO]]),
            ...$this->planStubs(),
        ]);

        // Jira's words, with the field named as people know it.
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['teamId' => '3688-dev'])
            ->assertStatus(422)->assertJsonPath('message', 'Jira did not take the change: Jira HTTP 400: Team: Field Team is not valid.');
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('team_id'));

        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['dueOn' => '2026-10-30'])
            ->assertForbidden()->assertJsonPath('message', 'Its kind, status, assignee, team, parent and dates change IKP-7 in Jira too, which needs Send to Jira.');
        // A report not in Jira keeps its plan here, without Send to Jira.
        $before = count(Http::recorded());
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$unsent, ['dueOn' => '2026-10-30', 'startOn' => '2026-10-23'])
            ->assertOk()->assertJsonPath('data.dueOn', '2026-10-30');
        $this->assertCount($before, Http::recorded());
    }

    public function test_a_plan_is_checked_before_anything_changes(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'due_on' => '2026-10-30']);
        Http::preventStrayRequests();
        Http::fake($this->planStubs());

        foreach ([
            [['dueOn' => '30/10/2026'], 'Write the due date as YYYY-MM-DD, like 2026-10-30.'],
            [['startOn' => '2026-02-30'], 'Write the start date as YYYY-MM-DD, like 2026-10-30.'],
            [['startOn' => '2026-10-31'], 'It cannot start after it is due.'],
            [['parentKey' => 'Payroll revamp'], 'Choose an epic for its parent.'],
            [['parentKey' => 'IKP-99'], 'IKP-99 is not an epic in IKP.'],
            [['teamId' => 'nobody'], 'Choose one of the teams in Jira.'],
        ] as [$body, $message]) {
            $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, $body)->assertStatus(422)->assertJsonPath('message', $message);
        }
        // An unknown epic is asked of Jira afresh once before it is refused.
        $this->assertCount(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/search/jql')));
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('start_on'));

        // Without Jira, the dates still change, and an epic or a team cannot be checked.
        config(['services.jira.base_url' => null]);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['startOn' => '2026-10-23'])->assertOk()->assertJsonPath('data.startOn', '2026-10-23');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['teamId' => '3688-dev'])->assertStatus(503)->assertJsonPath('message', 'Jira is not connected yet.');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['parentKey' => ''])->assertOk()->assertJsonPath('data.parent', null);
    }

    // Read back (2026-10-22, user): Jira's dates, epic and team come back; a field it did not send stays the portal's.
    public function test_jiras_plan_comes_back_and_what_it_does_not_send_stays(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $moved = $this->report(['jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent', 'jira_fields_synced_at' => '2026-10-22 01:00:00', 'due_on' => '2026-10-28', 'team_id' => '3688-dev', 'team_name' => 'DEV']);
        $kept = $this->report(['jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', 'jira_state' => 'sent', 'jira_fields_synced_at' => '2026-10-22 01:00:00', 'start_on' => '2026-10-20', 'team_id' => '3688-dev', 'team_name' => 'DEV',
            'parent_key' => 'IKP-1', 'parent_summary' => 'Payroll revamp']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => self::TO_DO, 'duedate' => '2026-11-05', 'customfield_10015' => '2026-10-24',
                'parent' => ['key' => 'IKP-1', 'fields' => ['summary' => 'Payroll, revamped']], 'customfield_10001' => ['id' => '9999-ops', 'title' => 'Ops']]],
            // Its team given by id alone keeps the name the portal has; its parent removed in Jira is gone here too.
            ['id' => '10051', 'key' => 'IKP-21', 'fields' => ['status' => self::TO_DO, 'customfield_10001' => '3688-dev']],
        ], 'issueErrors' => []])]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$moved, $kept])->orderBy('id')->get();
        $plan = fn (object $row): array => [
            $row->start_on === null ? null : substr((string) $row->start_on, 0, 10), $row->due_on === null ? null : substr((string) $row->due_on, 0, 10),
            $row->parent_key, $row->parent_summary, $row->team_id, $row->team_name,
        ];
        $this->assertSame(['2026-10-24', '2026-11-05', 'IKP-1', 'Payroll, revamped', '9999-ops', 'Ops'], $plan($rows[0]));
        $this->assertSame(['2026-10-20', null, null, null, '3688-dev', 'DEV'], $plan($rows[1]));
    }

    // The pickers' choices come apart from the list (2026-10-22, user), and only for who sees the list.
    public function test_the_plan_options_name_the_teams_the_epics_and_who_is_in_jira(): void
    {
        $this->connectJira();
        config(['services.jira.default_team' => 'DEV']);
        Http::preventStrayRequests();
        Http::fake([...$this->planStubs(), self::SITE.'/rest/api/3/user/search*' => Http::response([]),
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IK2', 'name' => 'Portal'])]);
        $mis = $this->headersFor($this->memberOf('MIS'));

        $options = $this->withHeaders($mis)->getJson(self::BASE.'bug-reports/jira/options')->assertOk()
            ->assertJsonPath('resource', 'jira-options')
            ->assertJsonPath('data.projectKey', 'IK2')
            ->assertJsonPath('data.teams', [['id' => '3688-dev', 'name' => 'DEV']])
            ->assertJsonPath('data.defaultTeamId', '3688-dev')
            ->assertJsonPath('data.epics', [['key' => 'IKP-1', 'summary' => 'Payroll revamp', 'isDone' => false]])
            ->assertJsonPath('data.fields', ['team' => true, 'startDate' => true])
            ->json('data.people');
        $this->assertNotEmpty($options);
        $this->assertFalse(collect($options)->contains('inJira', true));
        // Kept, so a second sheet asks Jira nothing.
        $asked = count(Http::recorded());
        $this->withHeaders($mis)->getJson(self::BASE.'bug-reports/jira/options')->assertOk();
        $this->assertCount($asked, Http::recorded());

        $this->withHeaders($this->headersFor($this->memberOf('Engineering')))->getJson(self::BASE.'bug-reports/jira/options')->assertForbidden();
        config(['services.jira.base_url' => null]);
        $this->withHeaders($mis)->getJson(self::BASE.'bug-reports/jira/options')->assertStatus(503)->assertJsonPath('message', 'Jira is not connected yet.');
    }

    // Who works on it (2026-10-22, user): assigning a linked report assigns its issue first; a member Jira has no
    // account for leaves it unassigned there, and stays assigned here when it is read back.
    public function test_assigning_a_linked_report_assigns_its_issue_too(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $headers = $this->headersFor($this->memberOf('MIS'));
        [$first, $second, $third] = $this->misAddresses(['first@ikaika.test', 'second@ikaika.test', 'third@ikaika.test']);
        $id = $this->report(['assignee_id' => $first, 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent', 'jira_assignee_id' => '712020:first']);
        $inJira = ['accountId' => '712020:first', 'displayName' => 'First'];
        $this->fakeAssignableIssue($inJira, ['first@ikaika.test' => '712020:first', 'second@ikaika.test' => '712020:second']);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $second])
            ->assertOk()->assertJsonPath('data.assignee.id', (string) $second)->assertJsonPath('data.jiraAssignee', ['state' => 'same', 'name' => null]);
        $this->assertSame('712020:second', $inJira['accountId'] ?? null);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['fields' => ['assignee' => ['accountId' => '712020:second']]]);

        // Third has no Jira account: the issue is unassigned there, and a read-back keeps them here.
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $third])
            ->assertOk()->assertJsonPath('data.jiraAssignee', ['state' => 'unassigned', 'name' => null]);
        $this->assertNull($inJira);
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
        $this->assertSame($third, (int) DB::connection('portal')->table('bug_reports')->where('id', $id)->value('assignee_id'));
        foreach (Http::recorded(fn (Request $request): bool => $request->method() === 'PUT') as [$request]) {
            $this->assertStringNotContainsString('@ikaika.test', $request->body());
        }

        // Without Send to Jira, who works on a linked report is not theirs to change.
        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => (string) $second])
            ->assertForbidden()->assertJsonPath('message', 'Its kind, status, assignee, team, parent and dates change IKP-7 in Jira too, which needs Send to Jira.');
    }

    // Read back, Jira's assignee comes back: an MIS member's account is that member; anyone else is named, and counts
    // as handling it until Jira lets them go -- or MIS does, from here.
    public function test_jiras_assignee_comes_back_and_someone_outside_mis_counts_as_handling_it(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $headers = $this->headersFor($this->memberOf('MIS'));
        [$member] = $this->misAddresses(['member@ikaika.test']);
        $id = $this->report(['jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $inJira = ['accountId' => '712020:member', 'displayName' => 'Member'];
        $this->fakeAssignableIssue($inJira, ['member@ikaika.test' => '712020:member']);
        $sync = fn () => $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
        $row = fn (): object => DB::connection('portal')->table('bug_reports')->where('id', $id)->first();

        $sync();
        $this->assertSame([$member, '712020:member', null], [(int) $row()->assignee_id, $row()->jira_assignee_id, $row()->jira_assignee_name]);

        $inJira = ['accountId' => '712020:outside', 'displayName' => 'Jane Doe'];
        $listed = collect($sync()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame([null, ['state' => 'other', 'name' => 'Jane Doe']], [$listed['assignee'], $listed['jiraAssignee']]);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeId' => ''])
            ->assertOk()->assertJsonPath('data.jiraAssignee', ['state' => 'same', 'name' => null]);
        $this->assertNull($inJira);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_review'])
            ->assertStatus(422)->assertJsonPath('message', 'Assign someone to handle it before changing it.');
    }

    // Off until MIS turns it on (2026-10-22, user: nothing changes in Jira on deploy): a new issue carries no assignee,
    // plan or team, a linked report's plan and assignee change here alone, and a read-back takes neither.
    public function test_with_the_field_sync_off_assignees_dates_parents_and_teams_stay_in_the_portal(): void
    {
        $this->connectJira();
        config(['services.jira.default_team' => 'dev']);
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $new = $this->report(['assignee_id' => $analyst->getKey(), 'due_on' => '2026-10-30', 'parent_key' => 'IKP-1', 'parent_summary' => 'Payroll revamp']);
        $linked = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent', 'due_on' => '2026-10-28']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201),
            self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
                ['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => self::TO_DO, 'assignee' => ['accountId' => '712020:outside', 'displayName' => 'Jane Doe'], 'duedate' => '2026-11-05']],
                ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => self::TO_DO, 'assignee' => null, 'duedate' => '2026-11-05', 'customfield_10001' => ['id' => '9999-ops', 'title' => 'Ops']]],
            ], 'issueErrors' => []]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => self::TO_DO]]),
            ...$this->planStubs(),
        ]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$new.'/jira')->assertOk()->assertJsonPath('data.jiraAssignee', null);
        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0]['fields'];
        foreach (['assignee', 'duedate', 'parent', 'customfield_10001', 'customfield_10015'] as $field) {
            $this->assertArrayNotHasKey($field, $created);
        }

        // Without Send to Jira its kind is not theirs to change, but its plan and who works on it are, and Jira hears nothing.
        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$linked, ['kind' => 'feature'])
            ->assertForbidden()->assertJsonPath('message', 'Its kind and status change IKP-20 in Jira too, which needs Send to Jira.');
        $before = count(Http::recorded());
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$linked, ['dueOn' => '2026-10-31'])->assertOk()->assertJsonPath('data.dueOn', '2026-10-31');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$linked, ['assigneeId' => ''])->assertOk()->assertJsonPath('data.assignee', null);
        $this->assertCount($before, Http::recorded());

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$new, $linked])->orderBy('id')->get();
        $kept = fn (object $row): array => [$row->assignee_id === null ? null : (int) $row->assignee_id, substr((string) $row->due_on, 0, 10), $row->team_id, $row->jira_assignee_id, $row->jira_fields_synced_at];
        $this->assertSame([(int) $analyst->getKey(), '2026-10-30', null, null, null], $kept($rows[0]));
        $this->assertSame([null, '2026-10-31', null, null, null], $kept($rows[1]));
    }

    // Turned on (2026-10-22, user: no mess when it goes live): the first read-back sends Jira what the portal has and
    // takes what only Jira has; from then on Jira's changes come back. One Jira refuses is tried again at the next.
    public function test_turning_the_field_sync_on_merges_each_side_once_then_follows_jira(): void
    {
        $headers = $this->headersFor($this->memberOf('MIS'));
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['syncFields' => true])->assertOk()->assertJsonPath('data.syncFields', true);
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        [$member] = $this->misAddresses(['member@ikaika.test']);
        $id = $this->report(['assignee_id' => $member, 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent', 'due_on' => '2026-10-28']);
        $fields = ['status' => self::TO_DO, 'assignee' => null, 'duedate' => '2026-11-05', 'customfield_10015' => '2026-10-24', 'customfield_10001' => ['id' => '9999-ops', 'title' => 'Ops']];
        $refuse = true;
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/user/search*' => Http::response([['accountId' => '712020:member', 'accountType' => 'atlassian', 'active' => true]]),
            self::SITE.'/rest/api/3/issue/bulkfetch' => function () use (&$fields) {
                return Http::response(['issues' => [['id' => '10042', 'key' => 'IKP-7', 'fields' => $fields]], 'issueErrors' => []]);
            },
            self::SITE.'/rest/api/3/issue/10042' => function (Request $request) use (&$refuse, &$fields) {
                if ($refuse) {
                    return Http::response(['errorMessages' => ['Not now.']], 400);
                }
                $fields = [...$fields, ...$request['fields']];

                return Http::response(null, 204);
            },
            ...$this->planStubs(),
        ]);
        $sync = fn () => $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
        $row = fn (): object => DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $state = fn (): array => [
            substr((string) $row()->start_on, 0, 10), substr((string) $row()->due_on, 0, 10), $row()->team_id, $row()->team_name,
            (int) $row()->assignee_id, $row()->jira_assignee_id, $row()->jira_fields_synced_at !== null,
        ];

        $sync();
        $this->assertSame(['', '2026-10-28', null, null, $member, null, false], $state());

        $refuse = false;
        $sync();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->data() === ['fields' => ['duedate' => '2026-10-28', 'assignee' => ['accountId' => '712020:member']]]);
        $this->assertSame(['2026-10-24', '2026-10-28', '9999-ops', 'Ops', $member, '712020:member', true], $state());

        $puts = count(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'));
        $fields['duedate'] = '2026-11-12';
        $sync();
        $this->assertSame('2026-11-12', substr((string) $row()->due_on, 0, 10));
        $this->assertCount($puts, Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'));
    }

    // The second switch (2026-10-22, user), beside the first and for the same people. Off forgets which reports were
    // in step, so turning it on again merges both sides afresh rather than taking Jira's over what changed here.
    public function test_mis_turns_the_field_sync_on_and_off_and_off_forgets_what_was_in_step(): void
    {
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent', 'jira_fields_synced_at' => '2026-10-22 01:00:00']);
        $stamp = fn (): ?string => DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_fields_synced_at');

        $this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->assertJsonPath('jira.syncFields', false);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['syncFields' => true])
            ->assertOk()->assertJsonPath('data.syncFields', true)->assertJsonPath('data.autoSend', true);
        foreach ([['syncFields' => 'on'], ['autoSend' => true, 'syncFields' => 1], []] as $body) {
            $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', $body)->assertStatus(422);
        }
        $this->assertNotNull($stamp());

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['syncFields' => false, 'autoSend' => false])
            ->assertOk()->assertJsonPath('data.syncFields', false)->assertJsonPath('data.autoSend', false);
        $this->assertNull($stamp());
        foreach (['on', 'off'] as $word) {
            $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())
                ->where('message', 'like', '%turned keeping assignees, dates, parents and teams in step with Jira '.$word)->exists());
        }

        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/jira', ['syncFields' => true])->assertForbidden();
        $this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->assertJsonPath('jira.syncFields', false);
    }

    // The others on it (2026-10-22, user): each one Jira has an account for watches its issue, and stops when they leave;
    // the save never waits on that. Only while fields are kept in step, and only the watchers the portal added.
    public function test_the_others_who_work_on_a_linked_report_watch_its_issue(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $headers = $this->headersFor($this->memberOf('MIS'));
        [$first, $second, $third] = $this->misAddresses(['first@ikaika.test', 'second@ikaika.test', 'third@ikaika.test']);
        $id = $this->report(['assignee_id' => $first, 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent',
            'jira_assignee_id' => '712020:first', 'jira_fields_synced_at' => '2026-10-22 01:00:00']);
        $inJira = ['accountId' => '712020:first', 'displayName' => 'First'];
        $refuse = false;
        $accounts = ['first@ikaika.test' => '712020:first', 'second@ikaika.test' => '712020:second'];
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/10042/watchers*' => function () use (&$refuse) {
                return $refuse ? Http::response(['errorMessages' => ['No.']], 403) : Http::response(null, 204);
            },
            self::SITE.'/rest/api/3/user/search*' => fn (Request $request) => Http::response(isset($accounts[$this->searchedFor($request)])
                ? [['accountId' => $accounts[$this->searchedFor($request)], 'accountType' => 'atlassian', 'active' => true]]
                : []),
            self::SITE.'/rest/api/3/issue/bulkfetch' => function () use (&$inJira) {
                return Http::response(['issues' => [['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'assignee' => $inJira]]], 'issueErrors' => []]);
            },
            self::SITE.'/rest/api/3/issue/10042*' => function () use (&$inJira) {
                return Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'assignee' => $inJira]]);
            },
        ]);
        $watching = fn (): array => Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/watchers'))
            ->map(fn (array $pair): string => $pair[0]->method().' '.($pair[0]->method() === 'POST' ? json_decode($pair[0]->body()) : urldecode(Str::after($pair[0]->url(), 'accountId='))))
            ->values()->all();
        $patch = fn (array $ids) => $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['assigneeIds' => array_map('strval', $ids)]);

        // Third has no Jira account, so watches nothing; the first stays its assignee, with no edit.
        $patch([$first, $second, $third])->assertOk()->assertJsonCount(3, 'data.assignees');
        $this->assertSame(['POST 712020:second'], $watching());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
        $patch([$first, $third])->assertOk();
        $this->assertSame(['POST 712020:second', 'DELETE 712020:second'], $watching());

        // Refused, the save stands and the log says so.
        $refuse = true;
        Log::spy();
        $patch([$first, $third, $second])->assertOk()->assertJsonCount(3, 'data.assignees');
        Log::shouldHaveReceived('warning')->with('portal.jira.watchers_refused', ['issue' => '10042', 'status' => 403])->once();
        $this->assertSame([$third, $second], DB::connection('portal')->table('bug_report_other_assignees')->where('bug_report_id', $id)->orderBy('position')->pluck('employee_id')->map(fn ($id): int => (int) $id)->all());

        // Jira making one of the others its assignee makes them the first, and one of the others no longer.
        $inJira = ['accountId' => '712020:second', 'displayName' => 'Second'];
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
        $this->assertSame($second, (int) DB::connection('portal')->table('bug_reports')->where('id', $id)->value('assignee_id'));
        $this->assertSame([$third], DB::connection('portal')->table('bug_report_other_assignees')->where('bug_report_id', $id)->pluck('employee_id')->map(fn ($id): int => (int) $id)->all());

        // With the field sync off, the others change here alone.
        DB::connection('core')->table('settings')->where('product', 'portal')->where('setting_key', 'jira.sync_fields')->delete();
        $before = count(Http::recorded());
        $patch([$second, $first])->assertOk();
        $this->assertCount($before, Http::recorded());
    }

    // Epics and sub-tasks in Jira (2026-10-22, user): an epic is filed as Epic, a report under it as its child, and one
    // under another report as that issue's sub-task -- while fields are kept in step; one under a report not in Jira
    // yet stands on its own there.
    public function test_epics_children_and_sub_tasks_are_filed_where_they_sit(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        $epic = $this->report(['kind' => 'epic', 'title' => 'Payroll revamp', 'jira_state' => 'pending']);
        $linkedEpic = $this->report(['kind' => 'epic', 'title' => 'Calendar', 'jira_issue_id' => '10001', 'jira_issue_key' => 'IKP-1', 'jira_state' => 'sent']);
        $child = $this->report(['parent_report_id' => $linkedEpic, 'jira_state' => 'pending']);
        $parent = $this->report(['title' => 'Payslips', 'jira_issue_id' => '10012', 'jira_issue_key' => 'IKP-12', 'jira_state' => 'sent']);
        $subtask = $this->report(['kind' => 'feature', 'parent_report_id' => $parent, 'jira_state' => 'pending']);
        $orphan = $this->report(['parent_report_id' => $epic, 'jira_state' => 'pending']);
        $next = 10100;
        Http::preventStrayRequests();
        Http::fake([
            ...$this->planStubs(),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => $this->hierarchyTypes()]),
            self::SITE.'/rest/api/3/issue' => function () use (&$next) {
                $next++;

                return Http::response(['id' => (string) $next, 'key' => 'IKP-'.$next], 201);
            },
            self::SITE.'/rest/api/3/issue/101*' => function (Request $request) {
                $issueId = Str::before(Str::after($request->url(), '/issue/'), '?');

                return Http::response(['id' => $issueId, 'key' => 'IKP-'.$issueId, 'fields' => ['status' => self::TO_DO]]);
            },
        ]);
        $headers = $this->headersFor($this->memberOf('MIS'));

        // Its epic not in Jira yet, the first goes on its own, and the page says so.
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$orphan.'/jira')->assertOk()->assertJsonPath('data.parentReport.inJira', false);
        foreach ([$epic, $child, $subtask] as $id) {
            $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')->assertOk();
        }

        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->map(fn (array $pair): array => $pair[0]['fields'])->values();
        $this->assertSame(['10001', null], [$created[0]['issuetype']['id'], $created[0]['parent'] ?? null]);
        $this->assertSame(['10000', null], [$created[1]['issuetype']['id'], $created[1]['parent'] ?? null]);
        $this->assertSame(['10001', ['key' => 'IKP-1']], [$created[2]['issuetype']['id'], $created[2]['parent']]);
        $this->assertSame(['10009', ['key' => 'IKP-12']], [$created[3]['issuetype']['id'], $created[3]['parent']]);
    }

    // A linked report moved to another level -- under a report, or made an epic -- is filed anew, as no edit can, and
    // its old issue is labelled, linked to the new one and cancelled. Jira refusing the new one changes nothing.
    public function test_a_linked_report_moved_to_another_level_is_filed_anew_and_its_old_issue_closed(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $parent = $this->report(['title' => 'Payslips', 'jira_issue_id' => '10007', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent',
            'jira_fields_synced_at' => '2026-10-22 01:00:00'], ['one.png' => 'image/png']);
        $refuse = true;
        Http::preventStrayRequests();
        Http::fake([
            ...$this->planStubs(),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => $this->hierarchyTypes()]),
            self::SITE.'/rest/api/3/issue' => function () use (&$refuse) {
                return $refuse ? Http::response(['errors' => ['parent' => 'Not now.']], 400) : Http::response(['id' => '10090', 'key' => 'IKP-40'], 201);
            },
            self::SITE.'/rest/api/3/issue/10090/attachments' => Http::response([['id' => '1']]),
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => $this->boardMoves()]),
            self::SITE.'/rest/api/3/issueLink' => Http::response(null, 201),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'issuetype' => ['name' => 'Bug', 'hierarchyLevel' => 0]]]),
            self::SITE.'/rest/api/3/issue/10090*' => Http::response(['id' => '10090', 'key' => 'IKP-40', 'fields' => ['status' => self::TO_DO, 'issuetype' => ['name' => 'Sub-task', 'subtask' => true]]]),
            self::SITE.'/rest/api/3/user/search*' => Http::response([]),
            self::SHOT.'*' => Http::response('png-bytes'),
        ]);
        $move = fn () => $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['parentReportId' => (string) $parent]);

        $move()->assertStatus(422)->assertJsonPath('message', 'Jira did not take the change: Jira HTTP 400: parent: Not now.');
        $this->assertSame(['10042', null], [DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_issue_id'),
            DB::connection('portal')->table('bug_reports')->where('id', $id)->value('parent_report_id')]);

        $refuse = false;
        $move()->assertOk()->assertJsonPath('data.jira.key', 'IKP-40')->assertJsonPath('data.parentReport.id', (string) $parent);
        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->last()[0]['fields'];
        $this->assertSame(['10009', ['key' => 'IKP-3']], [$created['issuetype']['id'], $created['parent']]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/10090/attachments'));
        // The old one: labelled, linked to the new, then cancelled.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_contains($request->url(), '/issue/10042') && $request->data() === ['update' => ['labels' => [['add' => 'moved']]]]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issueLink')
            && $request->data() === ['type' => ['name' => 'Relates'], 'inwardIssue' => ['id' => '10042'], 'outwardIssue' => ['id' => '10090']]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/issue/10042/transitions') && ($request['transition'] ?? null) === ['id' => '51']);
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['10090', 'IKP-40', 'sent', 1], [$row->jira_issue_id, $row->jira_issue_key, $row->jira_state, (int) $row->jira_attached]);
    }

    // Read back: under an issue this server follows, it sits under that report; under any other, an epic Jira alone
    // has; under none, out from under the report Jira had it under -- but not from under one not in Jira yet.
    public function test_where_jira_puts_a_report_comes_back_as_where_it_sits(): void
    {
        $this->syncFieldsOn();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $stamp = ['jira_state' => 'sent', 'jira_fields_synced_at' => '2026-10-22 01:00:00'];
        $first = $this->report(['kind' => 'epic', 'jira_issue_id' => '10001', 'jira_issue_key' => 'IKP-1', ...$stamp]);
        $second = $this->report(['kind' => 'epic', 'jira_issue_id' => '10002', 'jira_issue_key' => 'IKP-2', ...$stamp]);
        $pending = $this->report(['kind' => 'epic']);
        $moved = $this->report(['parent_report_id' => $first, 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', ...$stamp]);
        $toJiras = $this->report(['parent_report_id' => $first, 'jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', ...$stamp]);
        $out = $this->report(['parent_report_id' => $first, 'jira_issue_id' => '10052', 'jira_issue_key' => 'IKP-22', ...$stamp]);
        $waiting = $this->report(['parent_report_id' => $pending, 'jira_issue_id' => '10053', 'jira_issue_key' => 'IKP-23', ...$stamp]);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10001', 'key' => 'IKP-1', 'fields' => ['status' => self::TO_DO]],
            ['id' => '10002', 'key' => 'IKP-2', 'fields' => ['status' => self::TO_DO]],
            ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => self::TO_DO, 'parent' => ['key' => 'IKP-2', 'fields' => ['summary' => 'Calendar']]]],
            ['id' => '10051', 'key' => 'IKP-21', 'fields' => ['status' => self::TO_DO, 'parent' => ['key' => 'IKP-90', 'fields' => ['summary' => 'Jira only']]]],
            ['id' => '10052', 'key' => 'IKP-22', 'fields' => ['status' => self::TO_DO]],
            ['id' => '10053', 'key' => 'IKP-23', 'fields' => ['status' => self::TO_DO]],
        ], 'issueErrors' => []])]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$moved, $toJiras, $out, $waiting])->get()->keyBy('id');
        $place = fn (int $id): array => [$rows[$id]->parent_report_id === null ? null : (int) $rows[$id]->parent_report_id, $rows[$id]->parent_key];
        $this->assertSame([$second, null], $place($moved));
        $this->assertSame([null, 'IKP-90'], $place($toJiras));
        $this->assertSame([null, null], $place($out));
        $this->assertSame([$pending, null], $place($waiting));
    }

    public function test_a_linked_report_made_an_epic_is_filed_anew_as_one_even_with_the_field_sync_off(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => $this->hierarchyTypes()]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10090', 'key' => 'IKP-40'], 201),
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => $this->boardMoves()]),
            self::SITE.'/rest/api/3/issueLink' => Http::response(null, 201),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'issuetype' => ['name' => 'Bug', 'hierarchyLevel' => 0]]]),
            self::SITE.'/rest/api/3/issue/10090*' => Http::response(['id' => '10090', 'key' => 'IKP-40', 'fields' => ['status' => self::TO_DO, 'issuetype' => ['name' => 'Epic', 'hierarchyLevel' => 1]]]),
        ]);

        $this->withHeaders($this->headersFor($analyst))->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'epic'])
            ->assertOk()->assertJsonPath('data.kind', 'epic')->assertJsonPath('data.jira.key', 'IKP-40');
        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0]['fields'];
        $this->assertSame('10000', $created['issuetype']['id']);
        // With the field sync off it carries no assignee.
        $this->assertArrayNotHasKey('assignee', $created);
        // Jira's read-back of the Epic keeps it one.
        $this->withHeaders($this->headersFor($analyst))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();
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
    /**
     * A project's types with its levels (2026-10-22): an Epic over Bug, Story and Task, and a Sub-task under them.
     *
     * @return list<array<string, mixed>>
     */
    private function hierarchyTypes(): array
    {
        return [
            ['id' => '10000', 'name' => 'Epic', 'hierarchyLevel' => 1],
            ['id' => '10001', 'name' => 'Bug', 'hierarchyLevel' => 0],
            ['id' => '10002', 'name' => 'Story', 'hierarchyLevel' => 0],
            ['id' => '10003', 'name' => 'Task', 'hierarchyLevel' => 0],
            ['id' => '10009', 'name' => 'Sub-task', 'hierarchyLevel' => -1, 'subtask' => true],
        ];
    }

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
        // A create was started (2026-10-21): only then can an earlier try have made an issue to find.
        $id = $this->report(['jira_state' => 'failed', 'jira_attempts' => 1, 'jira_create_started_at' => '2026-10-19 09:00:00']);
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
            && str_contains(urldecode($request->url()), 'labels = "ikaika-portal"')
            && str_contains(urldecode($request->url()), 'created >= "2026-10-18"'));
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $id)->value('jira_create_started_at'));
    }

    // One project for production and staging (2026-10-21, user): staging labels what it files and names itself in the mark.
    public function test_staging_files_with_its_own_label_and_mark(): void
    {
        config(['products.channel' => 'staging']);
        $this->connectJira();
        $id = $this->report(['channel' => 'staging']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()->assertJsonPath('data.jira.key', 'IKP-12')->assertJsonPath('data.jiraHere', true);

        $created = Http::recorded(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue')->first()[0];
        $this->assertSame(['ikaika-portal', 'staging'], $created['fields']['labels']);
        $this->assertStringContainsString('(staging report #'.$id.')', $created->body());
        // No create was started before this one, so nothing was searched for first.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/search/jql'));
    }

    // Each server's retry takes only its own issue, even when Jira's word search finds the other's.
    public function test_a_retry_takes_only_the_issue_its_own_server_made(): void
    {
        $this->connectJira();
        config(['products.channel' => 'staging']);
        $id = $this->report(['channel' => 'staging', 'jira_state' => 'failed', 'jira_create_started_at' => '2026-10-21 02:00:00']);
        $said = fn (string $mark): array => ['type' => 'doc', 'version' => 1, 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sent anonymously from the IKAIKA portal '.$mark.'.']]],
        ]];
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => [
                ['id' => '10070', 'key' => 'IKP-40', 'fields' => ['description' => $said('(report #'.$id.')')]],
                ['id' => '10071', 'key' => 'IKP-41', 'fields' => ['description' => $said('(staging report #'.$id.')')]],
            ]]),
            self::SITE.'/rest/api/3/issue/10071*' => Http::response(['id' => '10071', 'key' => 'IKP-41', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()->assertJsonPath('data.jira.key', 'IKP-41');

        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'labels = "ikaika-portal" AND labels = "staging"'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/rest/api/3/issue'));
    }

    // The Test Server carries copies of production's reports: there they stay production's to send and to change.
    public function test_another_servers_report_is_left_to_that_server(): void
    {
        Queue::fake();
        $this->connectJira();
        config(['products.channel' => 'staging']);
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        DB::connection('portal')->table('bug_reports')->update(['jira_state' => 'sent']);
        $copied = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $unsent = $this->report();
        $sandbox = $this->report(['channel' => 'staging', 'jira_issue_id' => '10043', 'jira_issue_key' => 'SBX-3', 'jira_state' => 'failed']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKP', 'name' => 'Portal Issues'])]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$copied.'/jira')
            ->assertStatus(409)->assertJsonPath('message', "It came here with another server's data, so that server sends it to Jira and follows its issue.");
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$sandbox.'/jira')
            ->assertStatus(409)->assertJsonPath('message', 'SBX-3 is in another Jira project than IKP, so this server leaves it as it is.');
        // Its status changes in the portal only: the issue is the other server's, and the page is told why.
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$copied, ['status' => 'fixed'])
            ->assertOk()->assertJsonPath('data.status', 'fixed')->assertJsonPath('data.jiraHere', false)
            ->assertJsonPath('data.jiraElsewhere', "It came here with another server's data, so that server sends it to Jira and follows its issue.");
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/send-all')->assertOk()->assertJsonPath('queued', 0);

        // Jira is only asked which keys IKP goes by, once, to tell that SBX-3 is none of them.
        $this->assertSame(['GET project/IKP'], $this->calls());
        Queue::assertNothingPushed();
        $this->assertNull(DB::connection('portal')->table('bug_reports')->where('id', $unsent)->value('jira_state'));
    }

    // A project's key changed (2026-10-22, user: IPS became IP2). Jira still files under the old key, so a new issue
    // is IKN-4 while the configuration says IKP: this server's all the same, and followed both ways.
    public function test_a_project_whose_key_changed_is_still_followed_under_its_new_key(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $older = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10043', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $newer = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10044', 'jira_issue_key' => 'IKN-4', 'jira_state' => 'sent']);
        $story = ['status' => self::TO_DO, 'issuetype' => ['name' => 'Story']];
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKN', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10002', 'name' => 'Story']]]),
            self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
                ['id' => '10043', 'key' => 'IKN-3', 'fields' => ['status' => self::TO_DO]],
                ['id' => '10044', 'key' => 'IKN-4', 'fields' => $story],
            ], 'issueErrors' => []]),
            self::SITE.'/rest/api/3/issue/10044*' => Http::response(['id' => '10044', 'key' => 'IKN-4', 'fields' => $story]),
        ]);

        $listed = collect($this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([true, null], [$listed[(string) $newer]['jiraHere'], $listed[(string) $newer]['jiraElsewhere']]);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$newer, ['kind' => 'feature'])
            ->assertOk()->assertJsonPath('data.kind', 'feature');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/issue/10044') && $request['fields'] === ['issuetype' => ['id' => '10002']]);

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issue/bulkfetch') && $request['issueIdsOrKeys'] === ['10043', '10044']);
        // Read back, the older one keeps the key its issue has now.
        $this->assertSame('IKN-3', DB::connection('portal')->table('bug_reports')->where('id', $older)->value('jira_issue_key'));
        // Which keys the project goes by is asked once, not for every report on the page.
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/rest/api/3/project/IKP')));
    }

    // Once the configuration names the new key, a report still linked under the old one is found again by its issue's
    // id, which Jira answers with the key it has now. One in another project, or one Jira does not show, stays as it is.
    public function test_a_report_linked_under_an_old_key_is_found_again_by_its_issue(): void
    {
        $this->connectJira();
        config(['services.jira.project' => 'IKN']);
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $headers = $this->headersFor($this->memberOf('MIS'));
        $renamed = $this->report(['jira_issue_id' => '10043', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $sandbox = $this->report(['jira_issue_id' => '10090', 'jira_issue_key' => 'SBX-9', 'jira_state' => 'sent']);
        $unseen = $this->report(['jira_issue_id' => '10095', 'jira_issue_key' => 'OLD-5', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/project/IKN' => Http::response(['id' => '10000', 'key' => 'IKN', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
                ['id' => '10043', 'key' => 'IKN-3', 'fields' => ['status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']]]],
                ['id' => '10090', 'key' => 'SBX-9', 'fields' => ['status' => self::TO_DO]],
            ], 'issueErrors' => [['id' => '10095']]]),
        ]);

        $before = collect($this->withHeaders($headers)->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('IKP-3 is in another Jira project than IKN, so this server leaves it as it is.', $before[(string) $renamed]['jiraElsewhere']);

        $after = collect($this->withHeaders($headers)->postJson(self::BASE.'bug-reports/jira/sync')->assertOk()->json('data'))->keyBy('id');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issue/bulkfetch') && $request['issueIdsOrKeys'] === ['10043', '10090', '10095']);
        $this->assertSame([true, null, 'IKN-3', 'in_progress'], [$after[(string) $renamed]['jiraHere'], $after[(string) $renamed]['jiraElsewhere'], $after[(string) $renamed]['jira']['key'], $after[(string) $renamed]['status']]);
        $this->assertFalse($after[(string) $sandbox]['jiraHere']);
        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$sandbox, $unseen])->orderBy('id')->get(['jira_issue_key', 'jira_state']);
        $this->assertSame([['SBX-9', 'sent'], ['OLD-5', 'sent']], $rows->map(fn (object $row): array => [$row->jira_issue_key, $row->jira_state])->all());
    }

    // Without Jira's answer the configured key alone counts, and Jira is asked again in minutes, not for every report.
    public function test_without_an_answer_the_configured_key_alone_counts(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $one = $this->report(['jira_issue_id' => '10044', 'jira_issue_key' => 'IKN-4', 'jira_state' => 'sent']);
        $two = $this->report(['jira_issue_id' => '10045', 'jira_issue_key' => 'IKN-5', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/project/IKP' => Http::response(['errorMessages' => ["No project could be found with key 'IKP'."]], 404)]);

        $listed = collect($this->withHeaders($this->headersFor($this->memberOf('MIS')))->getJson(self::BASE.'bug-reports')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame([false, false], [$listed[(string) $one]['jiraHere'], $listed[(string) $two]['jiraHere']]);
        $this->assertSame('IKN-4 is in another Jira project than IKP, so this server leaves it as it is.', $listed[(string) $one]['jiraElsewhere']);
        Http::assertSentCount(1);
    }

    // A report moved on before it reached Jira (2026-10-21): its new issue goes to its column, not New.
    public function test_a_report_closed_before_it_reached_jira_lands_in_its_column(): void
    {
        $this->connectJira();
        $id = $this->report(['status' => 'fixed', 'jira_state' => 'pending']);
        $moved = false;
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10080', 'key' => 'IKP-50'], 201),
            self::SITE.'/rest/api/3/issue/10080/transitions' => function (Request $request) use (&$moved) {
                if ($request->method() === 'POST') {
                    $moved = true;

                    return Http::response(null, 204);
                }

                return Http::response(['transitions' => $this->boardMoves()]);
            },
            self::SITE.'/rest/api/3/issue/10080*' => function () use (&$moved) {
                $status = $moved ? ['name' => 'Done', 'statusCategory' => ['key' => 'indeterminate']] : ['name' => 'To Do', 'statusCategory' => ['key' => 'new']];

                return Http::response(['id' => '10080', 'key' => 'IKP-50', 'fields' => ['status' => $status]]);
            },
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()->assertJsonPath('data.status', 'fixed')->assertJsonPath('data.jira.status', 'Done');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/transitions') && $request['transition'] === ['id' => '41']);
    }

    // Until a new issue is in step with its report, a read-back takes nothing from it.
    public function test_a_read_back_while_a_create_is_under_way_takes_no_status(): void
    {
        $this->connectJira();
        $id = $this->report(['status' => 'fixed', 'jira_state' => 'pending', 'jira_issue_id' => '10081', 'jira_issue_key' => 'IKP-51', 'jira_create_started_at' => '2026-10-21 02:00:00']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10081', 'key' => 'IKP-51', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]],
        ], 'issueErrors' => []])]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['fixed', 'To Do'], [$row->status, $row->jira_status]);
    }

    // Triage (2026-10-21, user): a linked duplicate is labelled and linked to its original's issue, and moved last --
    // to Done since 2026-10-22 (user), as what it reports is fixed under the original.
    public function test_a_linked_duplicate_is_labelled_and_linked_to_its_originals_issue_before_it_is_fixed(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $original = $this->report(['title' => 'Calendar skips Sunday', 'jira_issue_id' => '10007', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $this->fakeBoard(['10042' => ['IKP-7', ['status' => self::DONE]]]);

        $this->withHeaders($this->headersFor($analyst))
            ->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed', 'cancelReason' => 'duplicate', 'duplicateOf' => (string) $original])
            ->assertOk()
            ->assertJsonPath('data.status', 'fixed')
            ->assertJsonPath('data.cancelReason', 'duplicate')
            ->assertJsonPath('data.duplicateOf', ['id' => (string) $original, 'title' => 'Calendar skips Sunday'])
            ->assertJsonPath('data.jira.status', 'Done');

        // The move found first and made last, so a delivery racing the save already sees a duplicate.
        $this->assertSame(['GET issue/10042/transitions', 'PUT issue/10042', 'POST issueLink', 'POST issue/10042/transitions', 'GET issue/10042'], $this->calls());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['update' => ['labels' => [['add' => 'duplicate']]]]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issueLink')
            && $request->data() === ['type' => ['name' => 'Duplicate'], 'inwardIssue' => ['id' => '10042'], 'outwardIssue' => ['id' => '10007']]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['transition'] ?? null) === ['id' => '41']);
    }

    // An original Jira lacks leaves nothing to link to; an app from before cancels as won't fix, with no label.
    public function test_a_duplicate_of_one_not_in_jira_is_only_labelled_and_an_older_apps_cancel_only_moves(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $original = $this->report(['title' => 'Calendar skips Sunday']);
        $duplicate = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $wontFix = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10043', 'jira_issue_key' => 'IKP-8', 'jira_state' => 'sent']);
        $this->fakeBoard(['10042' => ['IKP-7', ['status' => self::DONE]], '10043' => ['IKP-8', ['status' => self::CANCELLED]]]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$duplicate, ['status' => 'fixed', 'cancelReason' => 'duplicate', 'duplicateOf' => (string) $original])
            ->assertOk()->assertJsonPath('data.duplicateOf.id', (string) $original);
        $this->assertSame(['GET issue/10042/transitions', 'PUT issue/10042', 'POST issue/10042/transitions', 'GET issue/10042'], $this->calls());

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$wontFix, ['status' => 'wont_fix'])
            ->assertOk()->assertJsonPath('data.cancelReason', 'wont_fix');
        $this->assertSame(['GET issue/10043/transitions', 'POST issue/10043/transitions', 'GET issue/10043'], array_slice($this->calls(), 4));
    }

    // Pointed at another original, or moved on: the link it no longer stands for goes, and the label once it is no duplicate.
    public function test_a_duplicate_pointed_elsewhere_or_reopened_takes_back_its_link_and_label(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $first = $this->report(['title' => 'Calendar skips Sunday', 'jira_issue_id' => '10007', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $second = $this->report(['title' => 'Calendar skips weekends', 'jira_issue_id' => '10008', 'jira_issue_key' => 'IKP-4', 'jira_state' => 'sent']);
        $id = $this->report([
            'assignee_id' => $analyst->getKey(), 'status' => 'fixed', 'cancel_reason' => 'duplicate', 'duplicate_of' => $first,
            'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent',
        ]);
        $this->fakeBoard(['10042' => ['IKP-7', ['status' => self::DONE, 'labels' => ['ikaika-portal', 'duplicate'], 'issuelinks' => [
            ['id' => '30001', 'type' => ['name' => 'Duplicate'], 'outwardIssue' => ['id' => '10007']],
            ['id' => '30002', 'type' => ['name' => 'Duplicate'], 'outwardIssue' => ['id' => '10008']],
        ]]]]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['duplicateOf' => (string) $second])
            ->assertOk()->assertJsonPath('data.cancelReason', 'duplicate')->assertJsonPath('data.duplicateOf.id', (string) $second);
        $this->assertSame(['GET issue/10042', 'DELETE issueLink/30001', 'POST issueLink', 'GET issue/10042'], $this->calls());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['outwardIssue'] ?? null) === ['id' => '10008']);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.cancelReason', null)->assertJsonPath('data.duplicateOf', null);
        $this->assertSame(
            ['GET issue/10042/transitions', 'GET issue/10042', 'DELETE issueLink/30002', 'PUT issue/10042', 'POST issue/10042/transitions', 'GET issue/10042'],
            array_slice($this->calls(), 4),
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['update' => ['labels' => [['remove' => 'duplicate']]]]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['transition'] ?? null) === ['id' => '21']);
    }

    // Read back (2026-10-21, user): the portal's triage stands while Jira keeps the issue where it is, Jira's own is
    // worked out, a reopen clears it. A duplicate is Done since 2026-10-22 (user); one cancelled before stays so.
    public function test_reading_back_keeps_the_portals_triage_works_out_jiras_own_and_clears_it_on_a_reopen(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $original = $this->report(['title' => 'Calendar skips Sunday', 'jira_issue_id' => '10007', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $other = $this->report(['title' => 'Calendar skips weekends']);
        $kept = $this->report(['status' => 'wont_fix', 'cancel_reason' => 'duplicate', 'duplicate_of' => $other, 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent']);
        $byResolution = $this->report(['status' => 'in_progress', 'jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', 'jira_state' => 'sent']);
        $byLabel = $this->report(['status' => 'in_review', 'jira_issue_id' => '10052', 'jira_issue_key' => 'IKP-22', 'jira_state' => 'sent']);
        $wontFix = $this->report(['jira_issue_id' => '10053', 'jira_issue_key' => 'IKP-23', 'jira_state' => 'sent']);
        $reopened = $this->report(['status' => 'wont_fix', 'cancel_reason' => 'duplicate', 'duplicate_of' => $original, 'jira_issue_id' => '10054', 'jira_issue_key' => 'IKP-24', 'jira_state' => 'sent']);
        $fixedKept = $this->report(['status' => 'fixed', 'cancel_reason' => 'duplicate', 'duplicate_of' => $other, 'jira_issue_id' => '10055', 'jira_issue_key' => 'IKP-25', 'jira_state' => 'sent']);
        $doneByResolution = $this->report(['status' => 'in_progress', 'jira_issue_id' => '10056', 'jira_issue_key' => 'IKP-26', 'jira_state' => 'sent']);
        $doneByLabel = $this->report(['status' => 'in_review', 'jira_issue_id' => '10057', 'jira_issue_key' => 'IKP-27', 'jira_state' => 'sent']);
        $done = $this->report(['status' => 'in_review', 'jira_issue_id' => '10058', 'jira_issue_key' => 'IKP-28', 'jira_state' => 'sent']);
        $toOriginal = ['id' => '30001', 'type' => ['name' => 'Duplicate'], 'outwardIssue' => ['id' => '10007']];
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10007', 'key' => 'IKP-3', 'fields' => ['status' => self::TO_DO]],
            ['id' => '10050', 'key' => 'IKP-20', 'fields' => ['status' => self::CANCELLED, 'resolution' => ['name' => "Won't Do"]]],
            ['id' => '10051', 'key' => 'IKP-21', 'fields' => ['status' => self::CANCELLED, 'resolution' => ['name' => 'Duplicate'], 'issuelinks' => [$toOriginal]]],
            ['id' => '10052', 'key' => 'IKP-22', 'fields' => ['status' => self::CANCELLED, 'labels' => ['ikaika-portal', 'duplicate'], 'issuelinks' => [
                ['id' => '30009', 'type' => ['name' => 'Duplicate'], 'outwardIssue' => ['id' => '19999']],
            ]]],
            ['id' => '10053', 'key' => 'IKP-23', 'fields' => ['status' => self::CANCELLED, 'resolution' => ['name' => "Won't Do"]]],
            ['id' => '10054', 'key' => 'IKP-24', 'fields' => ['status' => self::TO_DO, 'labels' => ['ikaika-portal', 'duplicate'], 'issuelinks' => [$toOriginal]]],
            ['id' => '10055', 'key' => 'IKP-25', 'fields' => ['status' => self::DONE, 'resolution' => ['name' => 'Done']]],
            ['id' => '10056', 'key' => 'IKP-26', 'fields' => ['status' => self::DONE, 'resolution' => ['name' => 'Duplicate'], 'issuelinks' => [$toOriginal]]],
            ['id' => '10057', 'key' => 'IKP-27', 'fields' => ['status' => self::DONE, 'labels' => ['ikaika-portal', 'duplicate']]],
            ['id' => '10058', 'key' => 'IKP-28', 'fields' => ['status' => self::DONE, 'resolution' => ['name' => 'Done']]],
        ], 'issueErrors' => []])]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk();

        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$kept, $byResolution, $byLabel, $wontFix, $reopened, $fixedKept, $doneByResolution, $doneByLabel, $done])->get()->keyBy('id');
        $triage = fn (int $id): array => [$rows[$id]->status, $rows[$id]->cancel_reason, $rows[$id]->duplicate_of === null ? null : (int) $rows[$id]->duplicate_of];
        // The portal said which, and Jira says only Cancelled.
        $this->assertSame(['wont_fix', 'duplicate', $other], $triage($kept));
        // Cancelled in Jira: a duplicate by its resolution or its label, of the report whose issue it links to when this server has one.
        $this->assertSame(['wont_fix', 'duplicate', $original], $triage($byResolution));
        $this->assertSame(['wont_fix', 'duplicate', null], $triage($byLabel));
        $this->assertSame(['wont_fix', 'wont_fix', null], $triage($wontFix));
        // Moved on in Jira: cancelled no longer, with the label and the link left there for whoever moved it.
        $this->assertSame(['new', null, null], $triage($reopened));
        // Done in Jira: the portal's duplicate stands, and Jira's own is one by its resolution or its label.
        $this->assertSame(['fixed', 'duplicate', $other], $triage($fixedKept));
        $this->assertSame(['fixed', 'duplicate', $original], $triage($doneByResolution));
        $this->assertSame(['fixed', 'duplicate', null], $triage($doneByLabel));
        $this->assertSame(['fixed', null, null], $triage($done));
    }

    // A duplicate before it reached Jira (2026-10-21): its new issue is labelled, linked and moved to Done too.
    public function test_a_duplicate_sent_to_jira_lands_fixed_labelled_and_linked(): void
    {
        $this->connectJira();
        $original = $this->report(['title' => 'Calendar skips Sunday', 'jira_issue_id' => '10007', 'jira_issue_key' => 'IKP-3', 'jira_state' => 'sent']);
        $id = $this->report(['status' => 'fixed', 'cancel_reason' => 'duplicate', 'duplicate_of' => $original, 'jira_state' => 'pending']);
        $moved = false;
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10080', 'key' => 'IKP-50'], 201),
            self::SITE.'/rest/api/3/issueLink' => Http::response(null, 201),
            self::SITE.'/rest/api/3/issue/10080/transitions' => function (Request $request) use (&$moved) {
                if ($request->method() === 'POST') {
                    $moved = true;

                    return Http::response(null, 204);
                }

                return Http::response(['transitions' => $this->boardMoves()]);
            },
            self::SITE.'/rest/api/3/issue/10080*' => function (Request $request) use (&$moved) {
                return $request->method() === 'PUT'
                    ? Http::response(null, 204)
                    : Http::response(['id' => '10080', 'key' => 'IKP-50', 'fields' => ['status' => $moved ? self::DONE : self::TO_DO]]);
            },
        ]);

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertOk()
            ->assertJsonPath('data.status', 'fixed')
            ->assertJsonPath('data.cancelReason', 'duplicate')
            ->assertJsonPath('data.duplicateOf.id', (string) $original)
            ->assertJsonPath('data.jira.status', 'Done');

        $this->assertSame([
            'GET issue/createmeta/IKP/issuetypes', 'POST issue', 'GET issue/10080', 'GET issue/10080/transitions',
            'PUT issue/10080', 'POST issueLink', 'POST issue/10080/transitions', 'GET issue/10080',
        ], $this->calls());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issueLink')
            && $request->data() === ['type' => ['name' => 'Duplicate'], 'inwardIssue' => ['id' => '10080'], 'outwardIssue' => ['id' => '10007']]);
    }

    // Archive and delete (2026-10-21, user): an open linked one's issue moves to Cancelled first, and the report with it.
    public function test_archiving_an_open_linked_report_cancels_its_issue_first_and_a_closed_ones_stays(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $open = $this->report(['status' => 'in_progress', 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $fixed = $this->report(['status' => 'fixed', 'jira_issue_id' => '10043', 'jira_issue_key' => 'IKP-8', 'jira_state' => 'sent']);
        $this->fakeBoard(['10042' => ['IKP-7', ['status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']]]]]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$open.'/archive', ['archived' => true])
            ->assertOk()->assertJsonPath('data.status', 'wont_fix')->assertJsonPath('data.cancelReason', 'wont_fix');
        // Asked first, moved, and read back.
        $this->assertSame(['GET issue/10042', 'GET issue/10042/transitions', 'POST issue/10042/transitions', 'GET issue/10042'], $this->calls());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && ($request['transition'] ?? null) === ['id' => '51']);

        // A closed one's issue stays where it is, and unarchiving never touches Jira.
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$fixed.'/archive', ['archived' => true])->assertOk()->assertJsonPath('data.status', 'fixed');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$open.'/archive', ['archived' => false])->assertOk()->assertJsonPath('data.status', 'wont_fix');
        $this->assertCount(4, $this->calls());
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('record_id', (string) $open)->pluck('message')
            ->contains(fn (string $message): bool => str_ends_with($message, ' archived the bug report Export the map and moved IKP-7 to Cancelled in Jira')));
    }

    // Deleted the same way: cancelled in Jira first, and the bin says so.
    public function test_deleting_an_open_linked_report_cancels_its_issue_before_it_goes_to_the_bin(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $id = $this->report(['jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $this->fakeBoard(['10042' => ['IKP-7', ['status' => self::TO_DO]]]);

        $this->withHeaders($this->headersFor($analyst))->deleteJson(self::BASE.'bug-reports/'.$id)->assertNoContent();

        $this->assertSame(['GET issue/10042', 'GET issue/10042/transitions', 'POST issue/10042/transitions', 'GET issue/10042'], $this->calls());
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['wont_fix', 'wont_fix'], [$row->status, $row->cancel_reason]);
        $this->assertNotNull($row->deleted_at);
        $issue = Recycle::query()->where('product', 'portal')->where('recycle_key', 'bug-report-'.$id)->firstOrFail()->payload['issue'];
        $this->assertSame(['wont_fix', 'IKP-7', true], [$issue['status'], $issue['jiraKey'], $issue['jiraCancelled']]);
        $this->assertTrue(PortalLog::query()->where('employee_id', $analyst->getKey())->where('record_id', (string) $id)->pluck('message')
            ->contains(fn (string $message): bool => str_ends_with($message, ' deleted the bug report Export the map and moved IKP-7 to Cancelled in Jira')));
    }

    // Jira first, or nothing (2026-10-20): a move it refuses keeps the report, and so does lacking Send to Jira.
    public function test_a_removal_jira_refuses_changes_nothing_and_needs_send_to_jira(): void
    {
        $this->connectJira();
        $headers = $this->headersFor($this->memberOf('MIS'));
        $id = $this->report(['jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => [$this->boardMoves()[0]]]),
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO]]),
        ]);
        $refused = 'Jira did not take the change: Jira has no way to move IKP-7 to Cancelled from where it is.';

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => true])->assertStatus(422)->assertJsonPath('message', $refused);
        $this->withHeaders($headers)->deleteJson(self::BASE.'bug-reports/'.$id)->assertStatus(422)->assertJsonPath('message', $refused);
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['new', null, null], [$row->status, $row->archived_at, $row->deleted_at]);
        $this->assertFalse(Recycle::query()->where('product', 'portal')->where('recycle_key', 'bug-report-'.$id)->exists());

        // Without Send to Jira the issue is not theirs to move, so the report is not theirs to remove.
        $this->withHeaders($headers)->patchJson(self::BASE.'access/switches', ['key' => 'bug-reports.jira', 'on' => false])->assertOk();
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => true])
            ->assertForbidden()->assertJsonPath('message', 'Archiving it moves IKP-7 to Cancelled in Jira, which needs Send to Jira.');
        $this->withHeaders($headers)->deleteJson(self::BASE.'bug-reports/'.$id)
            ->assertForbidden()->assertJsonPath('message', 'Deleting it moves IKP-7 to Cancelled in Jira, which needs Send to Jira.');
    }

    // Gone from Jira, or closed there already: nothing to move, and the report goes as Jira has it.
    public function test_removing_one_whose_issue_is_gone_or_closed_in_jira_moves_nothing(): void
    {
        $this->connectJira();
        $headers = $this->headersFor($this->memberOf('MIS'));
        $gone = $this->report(['jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);
        $done = $this->report(['status' => 'in_review', 'jira_issue_id' => '10043', 'jira_issue_key' => 'IKP-8', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/10042*' => Http::response(['errorMessages' => ['Issue does not exist or you do not have permission to see it.']], 404),
            self::SITE.'/rest/api/3/issue/10043*' => Http::response(['id' => '10043', 'key' => 'IKP-8', 'fields' => ['status' => ['name' => 'Done', 'statusCategory' => ['key' => 'done']]]]),
        ]);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$gone.'/archive', ['archived' => true])
            ->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.jira.state', 'deleted');
        $this->withHeaders($headers)->deleteJson(self::BASE.'bug-reports/'.$done)->assertNoContent();

        $row = DB::connection('portal')->table('bug_reports')->where('id', $done)->first();
        $this->assertSame(['fixed', 'Done'], [$row->status, $row->jira_status]);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(['GET issue/10042', 'GET issue/10043'], $this->calls());
        $this->assertFalse(Recycle::query()->where('product', 'portal')->where('recycle_key', 'bug-report-'.$done)->firstOrFail()->payload['issue']['jiraCancelled']);
    }

    // A send holds the report: saving, archiving or deleting it waits rather than crossing it (saving: 2026-10-22).
    public function test_saving_archiving_or_deleting_one_being_sent_waits(): void
    {
        $this->connectJira();
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_state' => 'pending']);
        $lock = Cache::lock('portal-jira-push:'.$id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])
                ->assertStatus(409)->assertJsonPath('message', 'It is being sent to Jira right now. Try again in a moment.');
            $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id.'/archive', ['archived' => true])
                ->assertStatus(409)->assertJsonPath('message', 'It is being sent to Jira right now. Try again in a moment.');
            $this->withHeaders($headers)->deleteJson(self::BASE.'bug-reports/'.$id)
                ->assertStatus(409)->assertJsonPath('message', 'It is being sent to Jira right now. Try again in a moment.');
        } finally {
            $lock->release();
        }

        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        $this->assertSame(['new', null, null], [$row->status, $row->archived_at, $row->deleted_at]);
        // Once the send lets go, the same save goes through.
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    // Out of the way is out of Jira's way: an archived or deleted one is never queued or sent, even by a job from before.
    public function test_archived_and_deleted_reports_are_never_queued_or_sent(): void
    {
        Queue::fake();
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->update(['jira_state' => 'sent']);
        $archived = $this->report(['archived_at' => '2026-10-21 01:00:00']);
        $deleted = $this->report(['jira_state' => 'failed', 'deleted_at' => '2026-10-21 01:00:00']);
        Http::preventStrayRequests();
        Http::fake();

        $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/send-all')->assertOk()->assertJsonPath('queued', 0);
        Queue::assertNothingPushed();
        $this->assertTrue(app(PortalBugReportsJira::class)->push($archived));
        $this->assertTrue(app(PortalBugReportsJira::class)->push($deleted));

        Http::assertNothingSent();
        $rows = DB::connection('portal')->table('bug_reports')->whereIn('id', [$archived, $deleted])->get()->keyBy('id');
        $this->assertSame([null, 'failed'], [$rows[$archived]->jira_state, $rows[$deleted]->jira_state]);
    }

    // Nor read back: only the live one is asked about.
    public function test_archived_and_deleted_reports_are_never_read_back(): void
    {
        $this->connectJira();
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        $this->report(['archived_at' => '2026-10-21 01:00:00', 'jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent']);
        $this->report(['deleted_at' => '2026-10-21 01:00:00', 'jira_issue_id' => '10051', 'jira_issue_key' => 'IKP-21', 'jira_state' => 'sent']);
        $live = $this->report(['jira_issue_id' => '10052', 'jira_issue_key' => 'IKP-22', 'jira_state' => 'sent']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10052', 'key' => 'IKP-22', 'fields' => ['status' => self::TO_DO]],
        ], 'issueErrors' => []])]);

        $listed = $this->withHeaders($this->headersFor($this->memberOf('MIS')))->postJson(self::BASE.'bug-reports/jira/sync')->assertOk()->json('data');

        Http::assertSent(fn (Request $request): bool => $request['issueIdsOrKeys'] === ['10052']);
        $this->assertContains((string) $live, collect($listed)->pluck('id')->all());
    }

    /**
     * IKP-7 (10042) as a board whose assignee is $inJira: an edit sets it, every read and the bulk fetch say it, the
     * moves are the board's, and Jira finds an account for each of these addresses and for nobody else.
     *
     * @param  array{accountId: string, displayName: string}|null  $inJira
     * @param  array<string, string>  $accounts
     */
    private function fakeAssignableIssue(?array &$inJira, array $accounts): void
    {
        $issue = function () use (&$inJira): array {
            return ['id' => '10042', 'key' => 'IKP-7', 'fields' => ['status' => self::TO_DO, 'assignee' => $inJira]];
        };
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/user/search*' => fn (Request $request) => Http::response(isset($accounts[$this->searchedFor($request)])
                ? [['accountId' => $accounts[$this->searchedFor($request)], 'accountType' => 'atlassian', 'active' => true]]
                : []),
            self::SITE.'/rest/api/3/issue/bulkfetch' => fn () => Http::response(['issues' => [$issue()], 'issueErrors' => []]),
            self::SITE.'/rest/api/3/issue/10042/transitions' => Http::response(['transitions' => $this->boardMoves()]),
            self::SITE.'/rest/api/3/issue/10042*' => function (Request $request) use (&$inJira, $issue) {
                if ($request->method() === 'PUT') {
                    $account = $request['fields']['assignee']['accountId'] ?? null;
                    $inJira = $account === null ? null : ['accountId' => $account, 'displayName' => 'Someone'];

                    return Http::response(null, 204);
                }

                return Http::response($issue());
            },
        ]);
    }

    private function searchedFor(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return strtolower((string) ($query['query'] ?? ''));
    }

    /**
     * Active MIS members -- anyone a report can be assigned to -- each given one of these work addresses for the test.
     *
     * @param  list<string>  $addresses
     * @return list<int>
     */
    private function misAddresses(array $addresses): array
    {
        $ids = DB::connection('portal')->table('employees')
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = 'mis'")->whereRaw("LOWER(TRIM(COALESCE(status, ''))) = 'active'")
            ->orderBy('id')->limit(count($addresses))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertCount(count($addresses), $ids, count($addresses).' active MIS members are needed.');
        foreach ($ids as $index => $id) {
            DB::connection('portal')->table('employees')->where('id', $id)->update(['email' => $addresses[$index]]);
        }

        return $ids;
    }

    /**
     * What a report's plan is picked from (2026-10-22): the project's types with its epic type, each kind's create
     * screen (Task's without a start date), the site's one team, and one epic.
     *
     * @return array<string, mixed>
     */
    private function planStubs(): array
    {
        $screen = ['summary', 'issuetype', 'description', 'labels', 'assignee', 'parent', 'duedate', 'customfield_10001', 'customfield_10015'];

        return [
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/10001*' => Http::response(['fields' => array_map(fn (string $id): array => ['fieldId' => $id], $screen)]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/10003*' => Http::response(['fields' => array_map(fn (string $id): array => ['fieldId' => $id], array_diff($screen, ['customfield_10015']))]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [
                ['id' => '10000', 'name' => 'Epic', 'hierarchyLevel' => 1],
                ['id' => '10001', 'name' => 'Bug', 'hierarchyLevel' => 0],
                ['id' => '10002', 'name' => 'Story', 'hierarchyLevel' => 0],
                ['id' => '10003', 'name' => 'Task', 'hierarchyLevel' => 0],
            ]]),
            self::SITE.'/rest/api/3/jql/autocompletedata/suggestions*' => Http::response(['results' => [['value' => '3688-dev', 'displayName' => 'DEV']]]),
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => [['key' => 'IKP-1', 'fields' => ['summary' => 'Payroll revamp', 'status' => self::TO_DO]]]]),
        ];
    }

    /**
     * Linked issues on IPS's board: every write taken, and each read back as the fields given.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $issues  key and fields, by issue id
     */
    private function fakeBoard(array $issues): void
    {
        $stubs = [
            self::SITE.'/rest/api/3/issueLink' => Http::response(null, 201),
            self::SITE.'/rest/api/3/issueLink/*' => Http::response(null, 204),
        ];
        foreach ($issues as $id => [$key, $fields]) {
            $stubs[self::SITE.'/rest/api/3/issue/'.$id.'/transitions'] = Http::response(['transitions' => $this->boardMoves()]);
            $stubs[self::SITE.'/rest/api/3/issue/'.$id.'*'] = Http::response(['id' => (string) $id, 'key' => $key, 'fields' => $fields]);
        }
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /**
     * What has been asked of Jira so far, in order: each request's method and its path under the API.
     *
     * @return list<string>
     */
    private function calls(): array
    {
        return Http::recorded()
            ->map(fn (array $pair): string => $pair[0]->method().' '.Str::before(Str::after($pair[0]->url(), '/rest/api/3/'), '?'))
            ->values()
            ->all();
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

    // The field sync is off until MIS turns it on (2026-10-22, user); the tests of what it does turn it on.
    private function syncFieldsOn(): void
    {
        DB::connection('core')->table('settings')->insert(['product' => 'portal', 'setting_key' => 'jira.sync_fields', 'setting_value' => '1']);
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
