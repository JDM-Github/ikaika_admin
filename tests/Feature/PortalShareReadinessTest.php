<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The per-page readiness pass (docs/power/shared-pages-plan.md, Step 4): a page joins PAGE_READS the
 * day a guest token reads it cleanly, in the same change that seeds is_ready 1 for it. Held back so
 * far: the forms (email, reports.daily, reports.late, the request and edit pages) and
 * administration.backup, which has no route yet; the portal hides a guest's write controls on the
 * pages it has cleared. The two tests keep the map, the seed and the server from drifting apart.
 */
class PortalShareReadinessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    // The first read each ready page's screen asks for, per page-level rule.
    private const PAGE_READS = [
        'home' => ['/api/development/portal/home'],
        'projects.view' => ['/api/development/portal/projects'],
        'projects.track' => ['/api/development/portal/projects/track'],
        'projects.create' => ['/api/development/portal/projects/mine'],
        'projects.manager' => ['/api/development/portal/manage/projects'],
        'projects.references' => ['/api/development/portal/manage/references'],
        'reports.submitted' => ['/api/development/portal/reports/submitted'],
        'calendar' => ['/api/development/portal/calendar/events'],
        'manage.requests' => ['/api/development/portal/manage/requests'],
        'manage.reports' => ['/api/development/portal/manage/reports'],
        'manage.users' => ['/api/development/portal/manage/users'],
        'finance.overview' => ['/api/development/portal/manage/budget-codes', '/api/development/portal/manage/budget-transactions'],
        'finance.transactions' => ['/api/development/portal/manage/budget-transactions'],
        'finance.envelopes' => ['/api/development/portal/manage/budget-codes'],
        'finance.accounts' => ['/api/development/portal/manage/budget-accounts'],
        'administration.recycle' => ['/api/development/portal/administration/recycle-bin?scope=all'],
        'administration.logs' => ['/api/development/portal/administration/all-logs'],
        'administration.actions' => ['/api/development/portal/administration/all-actions'],
        'administration.missing' => ['/api/development/portal/administration/missing-reports'],
        'administration.emails' => ['/api/development/portal/administration/emails'],
        'email.library' => ['/api/development/portal/administration/email-blocks', '/api/development/portal/signature/default'],
        'user.logs' => ['/api/development/portal/user/logs'],
        'user.recycle' => ['/api/development/portal/administration/recycle-bin'],
        'requests.own' => ['/api/development/portal/requests/leave'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @param  list<string>  $rules
     * @return array{id: int, code: string, password: string}
     */
    private function share(array $rules): array
    {
        $code = bin2hex(random_bytes(12));
        $password = 'Guest-Pass-1';
        $id = (int) DB::connection('portal')->table('shared_pages')->insertGetId([
            'label' => 'Readiness',
            'code' => $code,
            'password_hash' => Hash::make($password),
            'email' => null,
            'expires_at' => now()->addDays(7),
            'rate_per_minute' => 600,
            'revoked_at' => null,
            'created_by' => 1,
        ]);
        foreach ($rules as $key) {
            DB::connection('portal')->table('shared_page_rules')->insert(['share_id' => $id, 'rule_key' => $key]);
        }

        return ['id' => $id, 'code' => $code, 'password' => $password];
    }

    /**
     * @return list<string>
     */
    private function readyRules(): array
    {
        return DB::connection('portal')->table('shared_page_shareable')
            ->where('is_ready', 1)
            ->pluck('rule_key')
            ->all();
    }

    public function test_the_ready_pages_read_for_a_guest(): void
    {
        $failures = [];
        foreach ($this->readyRules() as $rule) {
            $paths = self::PAGE_READS[$rule] ?? null;
            if ($paths === null) {
                $failures[] = "{$rule}: ready but no guest read is mapped.";

                continue;
            }
            $share = $this->share([$rule]);
            $token = (string) $this->postJson('/api/development/portal/share/'.$share['code'].'/access', ['password' => $share['password']])
                ->assertOk()
                ->json('token');
            foreach ($paths as $path) {
                $response = $this->withToken($token)->getJson($path);
                if ($response->status() !== 200) {
                    $failures[] = "{$rule} {$path}: ".$response->status().' '.substr((string) $response->getContent(), 0, 220);
                }
            }
        }

        $this->assertSame([], $failures, "Guest reads failed:\n".implode("\n", $failures));
    }

    // The seed and the map agree: nothing is offered as ready without a checked read, and no read
    // is asserted for a page the developer cannot pick.
    public function test_the_seed_matches_the_mapped_pages(): void
    {
        $ready = $this->readyRules();
        $mapped = array_keys(self::PAGE_READS);
        sort($ready);
        sort($mapped);

        $this->assertSame($mapped, $ready);
    }
}
