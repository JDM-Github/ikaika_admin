<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The guest sweep (2026-10-14, user): a share granting every ready page reads each one cleanly, and
 * no write lands anywhere -- the one write a guest may make is the email send, checked in
 * PortalGuestEmailTest. The write probes below are representative of every page's own actions.
 */
class PortalGuestReadOnlySweepTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SHARE = '/api/development/portal/share';

    // Every ready page's first read, as PortalShareReadinessTest maps them.
    private const READS = [
        '/api/development/portal/projects',
        '/api/development/portal/projects/track',
        '/api/development/portal/manage/references',
        '/api/development/portal/calendar/events',
        '/api/development/portal/manage/requests',
        '/api/development/portal/manage/reports',
        '/api/development/portal/manage/users',
        '/api/development/portal/manage/registrations',
        '/api/development/portal/manage/map',
        '/api/development/portal/manage/budget-codes',
        '/api/development/portal/manage/budget-transactions',
        '/api/development/portal/manage/budget-accounts',
        '/api/development/portal/administration/recycle-bin?scope=all',
        '/api/development/portal/administration/all-logs',
        '/api/development/portal/administration/all-actions',
        '/api/development/portal/administration/missing-reports',
        '/api/development/portal/administration/emails',
        '/api/development/portal/administration/emails/audiences',
    ];

    // One write per page's own actions: none may land, whatever the rule would say.
    private const WRITES = [
        ['post', '/api/development/portal/manage/references/scopes', ['name' => 'x']],
        ['patch', '/api/development/portal/manage/projects/1', ['status' => 'Completed']],
        ['post', '/api/development/portal/manage/registrations', ['candidateName' => 'x', 'email' => 'x@example.com']],
        ['patch', '/api/development/portal/manage/users/1/role', ['role' => 'Admin']],
        ['patch', '/api/development/portal/manage/requests', ['kind' => 'leave', 'id' => 1, 'action' => 'approve']],
        ['post', '/api/development/portal/manage/budget-codes', ['name' => 'x']],
        ['patch', '/api/development/portal/manage/budget-transactions/1', ['name' => 'x']],
        ['post', '/api/development/portal/manage/budget-accounts', ['name' => 'x']],
        ['post', '/api/development/portal/administration/recycle-bin/1/restore', []],
        ['post', '/api/development/portal/administration/email-blocks', ['kind' => 'footer', 'name' => 'x', 'body' => 'x']],
        ['post', '/api/development/portal/administration/email-images', ['name' => 'x']],
        ['post', '/api/development/portal/administration/email-attachments', []],
        ['patch', '/api/development/portal/manage/access/1', ['key' => 'home', 'action' => 'on']],
        ['post', '/api/development/portal/manage/flagged-members', ['employeeId' => 1]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
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
            'label' => 'Guest sweep',
            'code' => $code,
            'password_hash' => Hash::make($password),
            'email' => null,
            'require_otp' => false,
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

    public function test_a_guest_reads_every_ready_page_and_no_write_lands(): void
    {
        $rules = DB::connection('portal')->table('shared_page_shareable')->where('is_ready', 1)->pluck('rule_key')->all();
        $share = $this->share($rules);
        $token = (string) $this->postJson(self::SHARE.'/'.$share['code'].'/access', ['password' => $share['password']])
            ->assertOk()
            ->json('data.token');

        foreach (self::READS as $path) {
            $this->withToken($token)->getJson($path)->assertOk();
        }

        // The deepest reads the pages open: a row's own screen, where fixtures exist to open.
        $project = DB::connection('portal')->table('projects')->orderBy('id')->value('id');
        if ($project !== null) {
            $this->withToken($token)->getJson('/api/development/portal/projects/'.$project)->assertOk();
        }
        $user = DB::connection('portal')->table('employees')->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->orderBy('id')->value('id');
        if ($user !== null) {
            $this->withToken($token)->getJson('/api/development/portal/manage/users/'.$user)->assertOk();
        }
        $message = DB::connection('portal')->table('email_messages')->orderBy('id')->value('id');
        if ($message !== null) {
            $this->withToken($token)->getJson('/api/development/portal/administration/emails/'.$message)->assertOk();
        }
        $registration = DB::connection('portal')->table('registrations')->orderBy('id')->value('id');
        if ($registration !== null) {
            $this->withToken($token)->getJson('/api/development/portal/manage/registrations/'.$registration)->assertOk();
        }

        foreach (self::WRITES as [$method, $path, $body]) {
            $this->withToken($token)->json($method, $path, $body)
                ->assertStatus(403, "A guest write landed on {$method} {$path}.");
        }
    }
}
