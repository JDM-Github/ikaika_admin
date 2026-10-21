<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Jira's webhook (2026-10-19, user): trusted only when signed, only for an issue a report became, and read fresh after.
class PortalJiraWebhookTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const URL = '/api/development/jira/webhook';

    private const SECRET = 'fixture-jira-secret';

    private const SITE = 'https://ikaika-test.atlassian.net';

    private int $reportId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.jira' => [
            ...config('services.jira'),
            'base_url' => self::SITE,
            'email' => 'svc@ikaika.test',
            'token' => 'secret-token',
            'project' => 'IKP',
            'webhook_secret' => self::SECRET,
        ]]);
        $this->reportId = (int) DB::connection('portal')->table('bug_reports')->insertGetId([
            'title' => 'Calendar skips Sunday',
            'description' => 'The month view jumps from Saturday to Monday.',
            'status' => 'new',
            'reported_on' => '2026-10-19',
            'channel' => 'development',
            'jira_issue_id' => '10042',
            'jira_issue_key' => 'IKP-12',
            'jira_state' => 'sent',
        ]);
    }

    // One project for production and staging (2026-10-21, user): a copied report's issue is the other server's.
    public function test_a_delivery_about_another_servers_report_is_ignored(): void
    {
        $this->withoutDefer();
        DB::connection('portal')->table('bug_reports')->where('id', $this->reportId)->update(['channel' => null]);
        Http::preventStrayRequests();

        $this->deliver('jira:issue_updated', '10042')->assertAccepted()->assertJsonPath('status', 'ignored');

        Http::assertNothingSent();
    }

    public function test_a_signed_update_of_a_linked_issue_reads_it_fresh_and_moves_the_report(): void
    {
        $this->withoutDefer();
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/10042*' => Http::response(['id' => '10042', 'key' => 'IKP-12', 'fields' => [
            'status' => ['name' => 'Done', 'statusCategory' => ['key' => 'done']],
            'resolution' => ['name' => 'Done'],
            'fixVersions' => [['name' => '1.4.2']],
        ]])]);

        $this->deliver('jira:issue_updated', '10042')->assertAccepted()->assertJsonPath('status', 'accepted');
        // The same delivery again: Jira is read again, and the report stays where it is.
        $this->deliver('jira:issue_updated', '10042')->assertAccepted();

        $row = DB::connection('portal')->table('bug_reports')->where('id', $this->reportId)->first();
        $this->assertSame('fixed', $row->status);
        $this->assertSame('1.4.2', $row->fixed_in);
        $this->assertSame('Done', $row->jira_status);
        Http::assertSentCount(2);
    }

    public function test_an_issue_jira_deleted_leaves_the_report_to_the_portal(): void
    {
        $this->withoutDefer();
        Http::preventStrayRequests();
        Http::fake([self::SITE.'/rest/api/3/issue/10042*' => Http::response(['errorMessages' => ['Issue does not exist']], 404)]);

        $this->deliver('jira:issue_deleted', '10042')->assertAccepted();

        $this->assertSame('deleted', DB::connection('portal')->table('bug_reports')->where('id', $this->reportId)->value('jira_state'));
    }

    public function test_an_unsigned_or_wrongly_signed_delivery_is_refused_401(): void
    {
        Http::preventStrayRequests();

        $this->deliver('jira:issue_updated', '10042', null)->assertUnauthorized()->assertJsonPath('message', 'The signature does not match.');
        $this->deliver('jira:issue_updated', '10042', 'not-the-secret')->assertUnauthorized();
        config(['services.jira.webhook_secret' => '']);
        $this->deliver('jira:issue_updated', '10042', '')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_another_event_or_an_issue_no_report_became_is_ignored_and_jira_is_not_asked(): void
    {
        $this->withoutDefer();
        Http::preventStrayRequests();

        $this->deliver('comment_created', '10042')->assertAccepted()->assertJsonPath('status', 'ignored');
        $this->deliver('jira:issue_updated', '99999')->assertAccepted()->assertJsonPath('status', 'ignored');
        $this->deliver('jira:issue_updated', 'IKP-12')->assertAccepted()->assertJsonPath('status', 'ignored');

        Http::assertNothingSent();
    }

    private function deliver(string $event, string $issueId, ?string $secret = self::SECRET): TestResponse
    {
        $body = (string) json_encode(['webhookEvent' => $event, 'issue' => ['id' => $issueId, 'key' => 'IKP-12']]);
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }
}
