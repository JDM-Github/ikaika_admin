<?php

namespace Tests\Feature;

use App\Support\Portal\PortalCloudinary;
use App\Support\Portal\PortalJiraClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

// The Jira client (2026-10-19): one site, one project, credentials that never show, writes sent once.
class PortalJiraClientTest extends TestCase
{
    private const SITE = 'https://ikaika-test.atlassian.net';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.jira' => [
            ...config('services.jira'),
            'base_url' => self::SITE.'/',
            'email' => 'svc@ikaika.test',
            'token' => 'secret-token',
            'project' => 'ikp',
        ]]);
    }

    public function test_an_issue_is_created_with_the_service_account_and_the_connection_reads_ok(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue' => Http::response(['id' => '10042', 'key' => 'IKP-12'], 201)]);
        $client = app(PortalJiraClient::class);

        $created = $client->createIssue(['summary' => 'Export the map']);

        $this->assertSame(['id' => '10042', 'key' => 'IKP-12'], $created);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc@ikaika.test:secret-token'))
            && $request['fields'] === ['summary' => 'Export the map']);
        $this->assertSame('ok', $client->connection()['state']);
        $this->assertSame('https://ikaika-test.atlassian.net/browse/IKP-12', $client->browseUrl('IKP-12'));
    }

    public function test_a_refused_account_is_said_without_its_credentials_and_marks_the_connection_refused(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/myself' => Http::response(['errorMessages' => ['Client must be authenticated to access this resource.']], 401)]);
        $client = app(PortalJiraClient::class);

        try {
            $client->myself();
            $this->fail('A 401 was taken as an answer.');
        } catch (RuntimeException $error) {
            $this->assertSame('Jira HTTP 401: Client must be authenticated to access this resource.', $error->getMessage());
            $this->assertSame(401, $error->getCode());
        }

        $connection = $client->connection();
        $this->assertSame('refused', $connection['state']);
        $this->assertStringNotContainsString('secret-token', (string) $connection['message']);
        $this->assertStringNotContainsString('svc@ikaika.test', (string) $connection['message']);
    }

    public function test_a_read_is_tried_again_when_jira_stumbles_but_a_create_is_sent_once(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issue/10042*' => Http::sequence()
                ->push('', 503)
                ->push(['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'To Do']]], 200),
            self::SITE.'/rest/api/3/issue' => Http::response('', 503),
        ]);
        $client = app(PortalJiraClient::class);

        $issue = $client->issue('10042');
        try {
            $client->createIssue(['summary' => 'Twice?']);
            $this->fail('A 503 create was taken as an answer.');
        } catch (RuntimeException $error) {
            $this->assertSame(503, $error->getCode());
        }

        $this->assertSame('IKP-12', $issue['key']);
        Http::assertSentCount(3);
        $this->assertSame('unreachable', $client->connection()['state']);
    }

    // A scoped token (2026-10-19, user): refused at the site, answered only through Atlassian's gateway for it.
    public function test_a_cloud_id_sends_the_requests_through_the_gateway_and_keeps_the_links_on_the_site(): void
    {
        Http::preventStrayRequests();
        $gateway = 'https://api.atlassian.com/ex/jira/0f9a1c2e-3b4d-4e5f-8a6b-7c8d9e0f1a2b';
        config(['services.jira.cloud_id' => '0F9A1C2E-3B4D-4E5F-8A6B-7C8D9E0F1A2B']);
        Http::fake([$gateway.'/rest/api/3/myself' => Http::response(['accountId' => '5b10', 'displayName' => 'IKAIKA Portal Bot'])]);
        $client = app(PortalJiraClient::class);

        $client->myself();

        Http::assertSent(fn (Request $request): bool => $request->url() === $gateway.'/rest/api/3/myself'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('svc@ikaika.test:secret-token')));
        $this->assertSame(self::SITE.'/browse/IKP-12', $client->browseUrl('IKP-12'));
    }

    public function test_a_cloud_id_that_is_not_one_counts_as_unset_and_sends_nothing(): void
    {
        Http::preventStrayRequests();
        config(['services.jira.cloud_id' => 'ikaika-test.atlassian.net']);
        $client = app(PortalJiraClient::class);

        $this->assertSame(['JIRA_CLOUD_ID'], $client->missingSettings());
        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_while_jira_is_not_configured(): void
    {
        Http::preventStrayRequests();
        config(['services.jira.token' => '', 'services.jira.base_url' => 'http://ikaika-test.atlassian.net']);
        $client = app(PortalJiraClient::class);

        $this->expectExceptionMessage('Jira is not configured: JIRA_API_TOKEN, JIRA_BASE_URL.');
        try {
            $client->createIssue(['summary' => 'Nowhere']);
        } finally {
            Http::assertNothingSent();
            $this->assertSame(['state' => 'not_configured', 'message' => null, 'checkedAt' => null], $client->connection());
        }
    }

    public function test_bulk_fetch_tells_the_issues_found_from_the_ones_jira_no_longer_has(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/bulkfetch' => Http::response([
            'issues' => [['id' => '10042', 'key' => 'IKP-12', 'fields' => ['status' => ['name' => 'Done']]]],
            'issueErrors' => [],
        ], 200)]);

        $fetched = app(PortalJiraClient::class)->bulkFetch(['10042', '10043']);

        $this->assertSame(['10042'], array_column($fetched['issues'], 'id'));
        $this->assertSame(['10043'], $fetched['missing']);
        Http::assertSent(fn (Request $request): bool => $request['issueIdsOrKeys'] === ['10042', '10043']
            && $request['fields'] === ['status', 'resolution', 'fixVersions', 'issuetype', 'labels', 'issuelinks', 'parent', 'duedate', 'customfield_10001', 'customfield_10015']);
    }

    // Triage (2026-10-21, user): a label added through update operations, never fields, which would replace them all.
    public function test_a_label_is_added_and_removed_without_touching_the_others(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/10042' => Http::response(null, 204)]);
        $client = app(PortalJiraClient::class);

        $client->changeLabels('10042', ['duplicate']);
        $client->changeLabels('10042', [], ['duplicate']);
        $client->changeLabels('10042', []);

        $bodies = Http::recorded()->map(fn (array $pair): array => json_decode($pair[0]->body(), true))->values()->all();
        $this->assertSame([
            ['update' => ['labels' => [['add' => 'duplicate']]]],
            ['update' => ['labels' => [['remove' => 'duplicate']]]],
        ], $bodies);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    // The duplicate is the issue the link reads from, and goes in inwardIssue; a link already gone is gone.
    public function test_a_duplicate_is_linked_from_itself_to_its_original_and_a_link_jira_lost_counts_as_removed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/issueLink' => Http::response(null, 201),
            self::SITE.'/rest/api/3/issueLink/30001' => Http::response(['errorMessages' => ['No issue link with id 30001 exists.']], 404),
            self::SITE.'/rest/api/3/issueLinkType' => Http::response(['issueLinkTypes' => [['name' => 'Blocks'], ['name' => 'Duplicate']]]),
        ]);
        $client = app(PortalJiraClient::class);

        $client->linkIssues('Duplicate', '10042', '10007');
        $client->deleteIssueLink('30001');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::SITE.'/rest/api/3/issueLink'
            && $request->data() === ['type' => ['name' => 'Duplicate'], 'inwardIssue' => ['id' => '10042'], 'outwardIssue' => ['id' => '10007']]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/issueLink/30001'));
        $this->assertSame(['Blocks', 'Duplicate'], $client->issueLinkTypes());
        $this->assertSame('ok', $client->connection()['state']);
    }

    public function test_an_issue_jira_no_longer_has_reads_as_none_and_the_connection_stays_ok(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/10042*' => Http::response(['errorMessages' => ['Issue does not exist']], 404)]);
        $client = app(PortalJiraClient::class);

        $this->assertNull($client->issue('10042'));
        $this->assertSame('ok', $client->connection()['state']);
    }

    public function test_an_unknown_project_marks_the_connection_refused(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['errorMessages' => ['No project could be found with key IKP.']], 404)]);
        $client = app(PortalJiraClient::class);

        try {
            $client->issueTypes();
            $this->fail('A 404 project was taken as an answer.');
        } catch (RuntimeException) {
        }

        $this->assertSame('refused', $client->connection()['state']);
        $this->assertSame('Jira HTTP 404: No project could be found with key IKP.', $client->connection()['message']);
    }

    // The plan fields (2026-10-22, user): found once a day unless the env names them, and a failed look is tried
    // again in minutes rather than for every report on a page.
    public function test_the_plan_fields_are_found_on_the_site_once_and_a_failed_look_only_briefly(): void
    {
        config(['services.jira.fields' => ['team' => null, 'start_date' => null]]);
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/field' => Http::sequence()
            ->push(['errorMessages' => ['You do not have permission.']], 403)
            ->push([
                ['id' => 'customfield_10001', 'name' => 'Team', 'schema' => ['custom' => 'com.atlassian.jira.plugin.system.customfieldtypes:atlassian-team']],
                ['id' => 'customfield_10015', 'name' => 'Start date', 'schema' => ['custom' => 'com.atlassian.jira.plugin.system.customfieldtypes:datepicker']],
            ])]);
        $client = app(PortalJiraClient::class);

        $this->assertSame(['team' => null, 'startDate' => null], $client->fieldIds());
        $this->assertSame(['team' => null, 'startDate' => null], $client->fieldIds());
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->assertSame(['team' => 'customfield_10001', 'startDate' => 'customfield_10015'], $client->fieldIds());
        $client->fieldIds();
        Http::assertSentCount(2);
        // Named by the env, a field is taken as it is.
        config(['services.jira.fields' => ['team' => 'customfield_777', 'start_date' => 'customfield_778']]);
        $this->assertSame(['team' => 'customfield_777', 'startDate' => 'customfield_778'], $client->fieldIds());
        Http::assertSentCount(2);
    }

    public function test_the_teams_the_epics_and_a_create_screen_are_asked_once_and_kept(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::SITE.'/rest/api/3/jql/autocompletedata/suggestions*' => Http::response(['results' => [['value' => '3688-uuid', 'displayName' => '<b>DEV</b>']]]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/10004*' => Http::response(['fields' => [['fieldId' => 'summary'], ['fieldId' => 'assignee'], ['fieldId' => 'customfield_10001']]]),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes/99999*' => Http::response(['errorMessages' => ['No such issue type.']], 404),
            self::SITE.'/rest/api/3/issue/createmeta/IKP/issuetypes' => Http::response(['issueTypes' => [
                ['id' => '10000', 'name' => 'Epic', 'hierarchyLevel' => 1],
                ['id' => '10004', 'name' => 'Bug', 'hierarchyLevel' => 0],
                ['id' => '10009', 'name' => 'Subtask', 'subtask' => true, 'hierarchyLevel' => -1],
            ]]),
            self::SITE.'/rest/api/3/search/jql*' => Http::response(['issues' => [['key' => 'IKP-12', 'fields' => ['summary' => 'Payroll revamp', 'status' => ['name' => 'To Do']]]]]),
        ]);
        $client = app(PortalJiraClient::class);

        $this->assertSame([['id' => '3688-uuid', 'name' => 'DEV']], $client->teams());
        // An epic is an issue of the level-1 type, whatever the project calls it.
        $this->assertSame([['key' => 'IKP-12', 'summary' => 'Payroll revamp', 'isDone' => false]], $client->epics());
        $this->assertSame(['summary', 'assignee', 'customfield_10001'], $client->createFields('10004'));
        $client->teams();
        $client->epics();
        $client->createFields('10004');
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'fieldName=cf[10001]'));
        Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'project = "IKP" AND issuetype in (10000) ORDER BY created DESC'));
        $this->assertCount(4, Http::recorded());
        // Refresh asks for the epics again; a screen Jira cannot say is unknown, not empty.
        $client->forgetEpics();
        $client->epics();
        $this->assertCount(5, Http::recorded());
        $this->assertNull($client->createFields('99999'));
    }

    public function test_a_screenshot_is_downloaded_only_from_the_portals_own_cloudinary(): void
    {
        Http::preventStrayRequests();
        $cloudinary = app(PortalCloudinary::class);

        $this->expectExceptionMessage("That file is not one of the portal's.");
        try {
            $cloudinary->download('https://evil.example/upload/q_auto/shot.png');
        } finally {
            Http::assertNothingSent();
        }
    }
}
