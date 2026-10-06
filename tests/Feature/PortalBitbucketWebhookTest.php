<?php

namespace Tests\Feature;

use App\Support\Portal\PortalBitbucketSync;
use App\Support\Portal\PortalBitbucketWebhook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Bitbucket step 4: a push webhook is trusted only when signed with the shared secret, starts a sync only
 * for a linked repository, answers before syncing; one repository never syncs twice at once; and the
 * hourly sync is scheduled as the backstop.
 */
class PortalBitbucketWebhookTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const URL = '/api/development/bitbucket/webhook';

    private const SECRET = 'fixture-webhook-secret';

    private int $repositoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.bitbucket.token' => 'fixture-token', 'services.bitbucket.webhook_secret' => self::SECRET]);
        $portal = DB::connection('portal');
        $portal->table('projects_bitbucket_repositories')->delete();
        $projectId = (int) $portal->table('projects')->insertGetId([
            'project_name' => 'Bitbucket Webhook Fixture 2020',
            'department' => 'MIS',
            'status' => 'Started',
            'project_creation_status' => 'Approved',
        ]);
        $this->repositoryId = (int) $portal->table('bitbucket_repositories')->insertGetId(['workspace' => 'ikaika-web', 'repo_slug' => 'fixture-hook-2020']);
        $portal->table('projects_bitbucket_repositories')->insert(['project_id' => $projectId, 'repository_id' => $this->repositoryId]);
        $portal->table('bitbucket_repositories')->insert(['workspace' => 'ikaika-web', 'repo_slug' => 'fixture-unlinked-2020']);
    }

    private function deliver(string $fullName, string $event = 'repo:push', ?string $secret = self::SECRET): TestResponse
    {
        $body = (string) json_encode(['repository' => ['full_name' => $fullName]]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EVENT_KEY' => $event];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }

    // Bitbucket's own published test vector for its HMAC.
    public function test_the_signature_matches_bitbuckets_published_example(): void
    {
        config(['services.bitbucket.webhook_secret' => "It's a Secret to Everybody"]);
        $request = Request::create(self::URL, 'POST', [], [], [], [
            'HTTP_X_HUB_SIGNATURE' => 'sha256=a4771c39fbe90f317c7824e83ddef3caae9cb3d976c214ace1f2937e133263c9',
        ], 'Hello World!');

        $this->assertTrue(app(PortalBitbucketWebhook::class)->isSigned($request));
        $request->headers->set('X-Hub-Signature', 'sha256='.str_repeat('0', 64));
        $this->assertFalse(app(PortalBitbucketWebhook::class)->isSigned($request));
    }

    public function test_a_signed_push_for_a_linked_repository_syncs_it_after_answering(): void
    {
        $this->withoutDefer();
        Http::fake(['*' => Http::response(['values' => []])]);

        $this->deliver('IKAIKA-web/Fixture-Hook-2020')->assertStatus(202)->assertJsonPath('status', 'accepted');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/repositories/ikaika-web/fixture-hook-2020/commits'));
        $this->assertNotNull(DB::connection('portal')->table('bitbucket_repositories')->where('id', $this->repositoryId)->value('last_synced_at'));
    }

    public function test_an_unsigned_or_wrongly_signed_delivery_is_refused(): void
    {
        Http::fake();

        $this->deliver('ikaika-web/fixture-hook-2020', secret: null)->assertStatus(401);
        $this->deliver('ikaika-web/fixture-hook-2020', secret: 'someone-elses-secret')->assertStatus(401);
        config(['services.bitbucket.webhook_secret' => null]);
        $this->deliver('ikaika-web/fixture-hook-2020')->assertStatus(401);
        Http::assertNothingSent();
    }

    // Only pushes to a repository some project links are worth a sync.
    public function test_another_event_or_an_unlinked_repository_is_ignored(): void
    {
        $this->withoutDefer();
        Http::fake();

        $this->deliver('ikaika-web/fixture-unlinked-2020')->assertStatus(202)->assertJsonPath('status', 'ignored');
        $this->deliver('ikaika-web/fixture-hook-2020', 'pullrequest:created')->assertStatus(202)->assertJsonPath('status', 'ignored');
        $this->deliver('not-a-full-name')->assertStatus(202)->assertJsonPath('status', 'ignored');
        Http::assertNothingSent();
    }

    // A push arriving mid-way through the hourly sync waits its turn rather than syncing the same repository twice.
    public function test_one_repository_never_syncs_twice_at_once(): void
    {
        Http::fake();
        $repository = DB::connection('portal')->table('bitbucket_repositories')->where('id', $this->repositoryId)->first();
        $held = Cache::lock('portal-bitbucket-sync:'.$this->repositoryId, 60);
        $this->assertTrue($held->get());

        $this->assertSame('Already syncing.', app(PortalBitbucketSync::class)->sync($repository));
        Http::assertNothingSent();
        $held->release();
    }

    public function test_the_hourly_sync_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'portal:bitbucket-sync'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }
}
