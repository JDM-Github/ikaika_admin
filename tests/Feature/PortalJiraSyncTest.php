<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Jira's backstop (2026-10-19, user): the scheduled run sends what waits and reads every linked issue back; the check command.
class PortalJiraSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SITE = 'https://ikaika-test.atlassian.net';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        DB::connection('core')->table('settings')->where('product', 'portal')->where('setting_key', 'jira.auto_send')->delete();
        // Only this test's reports: the database may hold others from a copied server.
        DB::connection('portal')->table('bug_reports')->whereNotNull('jira_state')->update(['jira_state' => null, 'jira_issue_id' => null]);
        config(['services.jira' => [
            ...config('services.jira'),
            'base_url' => self::SITE,
            'email' => 'svc@ikaika.test',
            'token' => 'secret-token',
            'project' => 'IKP',
            'webhook_secret' => 'fixture-secret',
        ]]);
    }

    public function test_the_run_sends_what_waits_skips_what_ran_out_of_tries_and_reads_linked_issues_back(): void
    {
        $waiting = $this->report(['jira_state' => 'pending']);
        $spent = $this->report(['jira_state' => 'failed', 'jira_attempts' => 5]);
        $linked = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10003', 'jira_issue_key' => 'IKP-3']);
        $gone = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10004', 'jira_issue_key' => 'IKP-4']);
        $sandbox = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '20001', 'jira_issue_key' => 'PROD-1']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => []]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug']]]),
            self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
                ['id' => '10003', 'key' => 'IKP-3', 'fields' => ['status' => ['name' => 'Done', 'statusCategory' => ['key' => 'done']], 'fixVersions' => [['name' => '1.2.0']]]],
                ['id' => '10090', 'key' => 'IKP-9', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]],
            ], 'issueErrors' => []]),
            self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10090', 'key' => 'IKP-9'], 201),
            self::SITE.'/rest/api/3/issue/10090*' => Http::response(['id' => '10090', 'key' => 'IKP-9', 'fields' => ['status' => ['name' => 'To Do', 'statusCategory' => ['key' => 'new']]]]),
        ]);

        $this->artisan('portal:jira-sync')->expectsOutputToContain('Sent 1, failed 0, read back 2, gone from Jira 1.')->assertExitCode(0);

        $this->assertSame('sent', $this->state($waiting));
        $this->assertSame('failed', $this->state($spent));
        $this->assertSame(['fixed', '1.2.0'], [$this->column($linked, 'status'), $this->column($linked, 'fixed_in')]);
        $this->assertSame('deleted', $this->state($gone));
        // Another project's issue - production's, on a copied database - is never asked about.
        $this->assertSame('sent', $this->state($sandbox));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue/bulkfetch'
            && $request['issueIdsOrKeys'] === ['10090', '10003', '10004']);
    }

    public function test_with_sending_off_nothing_waiting_is_sent_but_linked_issues_are_still_read(): void
    {
        DB::connection('core')->table('settings')->insert(['product' => 'portal', 'setting_key' => 'jira.auto_send', 'setting_value' => '0']);
        $waiting = $this->report(['jira_state' => 'pending']);
        $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10003', 'jira_issue_key' => 'IKP-3']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [['id' => '10003', 'key' => 'IKP-3', 'fields' => []]]])]);

        $this->artisan('portal:jira-sync')->assertExitCode(0);

        $this->assertSame('pending', $this->state($waiting));
        Http::assertSentCount(1);
    }

    public function test_a_refused_account_stops_the_run_and_the_command_fails(): void
    {
        $first = $this->report(['jira_state' => 'pending']);
        $second = $this->report(['jira_state' => 'pending']);
        Http::preventStrayRequests();
        // Neither has started a create, so the first thing asked is the project's issue types (2026-10-21).
        Http::fake([
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401),
        ]);

        $this->artisan('portal:jira-sync')->expectsOutputToContain('Jira: Jira HTTP 401')->assertExitCode(1);

        $this->assertSame('failed', $this->state($first));
        $this->assertSame('pending', $this->state($second));
        Http::assertSentCount(1);
    }

    // Every issue asked about missing at once says the account lost the project, not that the issues went.
    public function test_every_linked_issue_missing_at_once_marks_none_deleted(): void
    {
        $one = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10003', 'jira_issue_key' => 'IKP-3']);
        $two = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10004', 'jira_issue_key' => 'IKP-4']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [], 'issueErrors' => [['id' => '10003'], ['id' => '10004']]])]);

        $this->artisan('portal:jira-sync')->expectsOutputToContain('check the account can still browse the project')->assertExitCode(1);

        $this->assertSame(['sent', 'sent'], [$this->state($one), $this->state($two)]);
    }

    public function test_the_check_names_the_account_project_and_types_and_prints_the_webhook(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/myself' => Http::response(['accountId' => '5b10', 'displayName' => 'IKAIKA Portal Bot']),
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKP', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task']]]),
            self::SITE.'/rest/api/3/issueLinkType' => Http::response(['issueLinkTypes' => [['name' => 'Blocks'], ['name' => 'Duplicate']]]),
        ]);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('Account: IKAIKA Portal Bot')
            ->expectsOutputToContain('Duplicates are linked as: Duplicate')
            ->expectsOutputToContain('Feature request -> Task (no Story in the project)')
            // One project for every server (2026-10-21, user): this one says how its issues are told apart.
            ->expectsOutputToContain('Files as: development - labels ikaika-portal, development; marked (development report #N)')
            ->expectsOutputToContain('/api/development/jira/webhook')
            ->expectsOutputToContain('JQL:    project = IKP AND labels = ikaika-portal AND labels = development')
            ->assertExitCode(0);
    }

    // A report's plan is the issue's too (2026-10-22, user): the check names the two fields, the team new issues go
    // to and the epics, and what a kind's create screen lacks, which a new issue is then sent without.
    public function test_the_check_names_the_plan_fields_the_default_team_and_the_epics(): void
    {
        config(['services.jira.default_team' => 'dev']);
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/myself' => Http::response(['accountId' => '5b10', 'displayName' => 'IKAIKA Portal Bot']),
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKP', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/10001*' => Http::response(['fields' => [
                ['fieldId' => 'summary'], ['fieldId' => 'assignee'], ['fieldId' => 'parent'], ['fieldId' => 'duedate'], ['fieldId' => 'customfield_10001'], ['fieldId' => 'customfield_10015'],
            ]]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/10003*' => Http::response(['fields' => [['fieldId' => 'summary'], ['fieldId' => 'assignee']]]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [
                ['id' => '10000', 'name' => 'Epic', 'hierarchyLevel' => 1], ['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task'],
            ]]),
            self::SITE.'/rest/api/3/issueLinkType' => Http::response(['issueLinkTypes' => [['name' => 'Duplicate']]]),
            self::SITE.'/rest/api/3/jql/autocompletedata/suggestions*' => Http::response(['results' => [['value' => '3688-uuid', 'displayName' => 'DEV']]]),
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => [['key' => 'IKP-12', 'fields' => ['summary' => 'Payroll revamp']]]]),
        ]);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('Team field: customfield_10001 (set by JIRA_FIELD_TEAM)')
            ->expectsOutputToContain('Start date field: customfield_10015 (set by JIRA_FIELD_START_DATE)')
            ->expectsOutputToContain('New issues go to team: DEV (3688-uuid)')
            ->expectsOutputToContain('Epics: 1 in IKP')
            ->expectsOutputToContain('Feature request (Task) has no parent, duedate, customfield_10001, customfield_10015 on its create screen, so a new one goes without.')
            ->assertExitCode(0);
    }

    // A key the project had before still files (2026-10-22, user: IPS became IP2): the check says to move to the key it
    // has now, and prints the webhook's JQL with that one.
    public function test_the_check_names_the_key_a_renamed_project_has_now(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/myself' => Http::response(['accountId' => '5b10', 'displayName' => 'IKAIKA Portal Bot']),
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKN', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task']]]),
            self::SITE.'/rest/api/3/issueLinkType' => Http::response(['issueLinkTypes' => [['name' => 'Duplicate']]]),
        ]);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('Project: IKN - Portal Issues')
            ->expectsOutputToContain('JIRA_PROJECT_KEY is a key IKN had before: Jira files new issues as IKN-N, which this server follows too. Set JIRA_PROJECT_KEY=IKN to match.')
            ->expectsOutputToContain('JQL:    project = IKN AND labels = ikaika-portal AND labels = development')
            ->assertExitCode(0);
    }

    // Triage links a duplicate to its original (2026-10-21, user), by a link type the site must have.
    public function test_the_check_fails_without_the_duplicate_link_type(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/myself' => Http::response(['accountId' => '5b10', 'displayName' => 'IKAIKA Portal Bot']),
            self::SITE.'/rest/api/3/project/IKP' => Http::response(['id' => '10000', 'key' => 'IKP', 'name' => 'Portal Issues']),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [['id' => '10001', 'name' => 'Bug'], ['id' => '10003', 'name' => 'Task']]]),
            self::SITE.'/rest/api/3/issueLinkType' => Http::response(['issueLinkTypes' => [['name' => 'Blocks'], ['name' => 'Relates']]]),
        ]);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('No Duplicate link type on the site, so a duplicate cannot be linked to its original. The site has: Blocks, Relates.')
            ->assertExitCode(1);
    }

    // The Test Server carries copies of production's reports (2026-10-21, user): in the shared project they stay production's.
    public function test_another_servers_reports_are_never_sent_or_read_back(): void
    {
        $copied = $this->report(['channel' => null, 'jira_state' => 'sent', 'jira_issue_id' => '10005', 'jira_issue_key' => 'IKP-5']);
        $waitingThere = $this->report(['channel' => 'production', 'jira_state' => 'pending']);
        $own = $this->report(['jira_state' => 'sent', 'jira_issue_id' => '10003', 'jira_issue_key' => 'IKP-3']);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response(['issues' => [
            ['id' => '10003', 'key' => 'IKP-3', 'fields' => ['status' => ['name' => 'In Progress', 'statusCategory' => ['key' => 'indeterminate']]]],
        ], 'issueErrors' => []])]);

        $this->artisan('portal:jira-sync')->expectsOutputToContain('Sent 0, failed 0, read back 1, gone from Jira 0.')->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SITE.'/rest/api/3/issue/bulkfetch' && $request['issueIdsOrKeys'] === ['10003']);
        Http::assertSentCount(1);
        $this->assertSame(['sent', 'pending', 'in_progress'], [$this->state($copied), $this->state($waitingThere), $this->column($own, 'status')]);
    }

    public function test_the_check_fails_on_a_refused_account_without_showing_its_token(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/myself' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401),
            self::SITE.'/_edge/tenant_info' => Http::response(['cloudId' => '0f9a1c2e-3b4d-4e5f-8a6b-7c8d9e0f1a2b']),
        ]);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('Jira HTTP 401: Client must be authenticated to access this resource.')
            ->expectsOutputToContain('set JIRA_CLOUD_ID=0f9a1c2e-3b4d-4e5f-8a6b-7c8d9e0f1a2b, or use a classic token')
            ->doesntExpectOutputToContain('secret-token')
            ->assertExitCode(1);
    }

    public function test_the_check_names_the_settings_still_missing(): void
    {
        config(['services.jira.token' => '', 'services.jira.project' => '']);

        $this->artisan('portal:jira-check')
            ->expectsOutputToContain('Jira is not configured: set JIRA_API_TOKEN, JIRA_PROJECT_KEY')
            ->assertExitCode(1);
    }

    public function test_the_run_is_scheduled_every_fifteen_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'portal:jira-sync'));

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function report(array $columns): int
    {
        return (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Calendar skips Sunday',
            'description' => 'The month view jumps from Saturday to Monday.',
            'status' => 'new',
            'reported_on' => '2026-10-19',
            // Sent on this server, as a test's is unless it says otherwise.
            'channel' => 'development',
            ...$columns,
        ]);
    }

    private function state(int $id): ?string
    {
        return $this->column($id, 'jira_state');
    }

    private function column(int $id, string $column): mixed
    {
        return DB::connection('portal')->table('bug_reports')->where('id', $id)->value($column);
    }
}
