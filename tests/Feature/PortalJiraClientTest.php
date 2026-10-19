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
            && $request['fields'] === ['status', 'resolution', 'fixVersions']);
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
