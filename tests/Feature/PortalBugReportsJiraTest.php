<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame(['ikaika-portal', 'ikaika-portal-'.$id, 'portal-feature'], $created['fields']['labels']);
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

    public function test_sending_needs_jira_connected_and_is_refused_while_it_is_already_there_or_on_its_way(): void
    {
        $headers = $this->headersFor($this->memberOf('MIS'));
        $id = $this->report();
        $sent = $this->report(['jira_issue_id' => '10050', 'jira_issue_key' => 'IKP-20', 'jira_state' => 'sent']);
        Http::preventStrayRequests();

        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertStatus(503)->assertJsonPath('message', 'Jira is not connected yet.');
        $this->connectJira();
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$sent.'/jira')
            ->assertConflict()->assertJsonPath('message', 'It is already in Jira as IKP-20.');
        Cache::lock('portal-jira-push:'.$id, 120)->get();
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/'.$id.'/jira')
            ->assertConflict()->assertJsonPath('message', 'It is being sent right now.');
        $this->withHeaders($headers)->postJson(self::BASE.'bug-reports/999999999/jira')->assertNotFound();

        Http::assertNothingSent();
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

    public function test_jira_owns_a_linked_reports_status_fixed_in_and_kind_until_it_deletes_the_issue(): void
    {
        $analyst = $this->memberOf('MIS');
        $headers = $this->headersFor($analyst);
        $id = $this->report(['assignee_id' => $analyst->getKey(), 'jira_issue_id' => '10042', 'jira_issue_key' => 'IKP-7', 'jira_state' => 'sent']);

        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertStatus(422)->assertJsonPath('message', 'Its status is managed in Jira (IKP-7).');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['fixedIn' => '1.4.2'])
            ->assertStatus(422)->assertJsonPath('message', 'Its fixed-in version is managed in Jira (IKP-7).');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['kind' => 'feedback'])
            ->assertStatus(422)->assertJsonPath('message', 'Its kind is set in Jira (IKP-7).');
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['note' => 'Waiting on Jira.', 'versionLevel' => 'patch'])
            ->assertOk()->assertJsonPath('data.note', 'Waiting on Jira.');

        DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['jira_state' => 'deleted']);
        $this->withHeaders($headers)->patchJson(self::BASE.'bug-reports/'.$id, ['status' => 'fixed'])
            ->assertOk()->assertJsonPath('data.status', 'fixed')->assertJsonPath('data.jira.state', 'deleted');
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
