<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Developer mode: is_developer grants nothing until the developer switches the mode on with their
 * password, and it ends at sign-out. In Default they are their role, bound by their own rules.
 */
class PortalDeveloperModeTest extends TestCase
{
    use DatabaseTransactions;

    private const NEW_PASSWORD = 'correct-horse-9';

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private Employee $developer;

    private Employee $admin;

    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        // The flows below take more than the five attempts a minute; the limit has its own test.
        $this->withoutMiddleware(ThrottleRequests::class);

        [$this->developer, $this->member] = $this->active("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'", 2);
        $this->admin = $this->active("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'", 1)[0];

        // A member-role developer, so Default visibly means "a member".
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
        $this->admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        foreach (['access_grants', 'access_limits', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
    }

    public function test_the_default_password_works_once_and_only_with_a_new_one(): void
    {
        $token = $this->login($this->developer);
        $this->me($token)->assertJsonPath('employee.developer_mode', false);

        $this->mode($token, ['on' => true, 'password' => '123'])->assertStatus(428);
        $this->mode($token, ['on' => true, 'password' => '123', 'newPassword' => 'short'])->assertStatus(422);
        $this->mode($token, ['on' => true, 'password' => '123', 'newPassword' => '123'])->assertStatus(422);
        $this->mode($token, ['on' => true, 'password' => '123', 'newPassword' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertJsonPath('employee.developer_mode', true);
        $this->me($token)->assertJsonPath('employee.developer_mode', true);

        $next = $this->login($this->developer);
        $this->me($next)->assertJsonPath('employee.developer_mode', false);
        $this->mode($next, ['on' => true, 'password' => '123'])->assertStatus(422);
        $this->mode($next, ['on' => true, 'password' => 'wrong-password'])->assertStatus(422);
        $this->mode($next, ['on' => true, 'password' => self::NEW_PASSWORD])->assertOk()->assertJsonPath('employee.developer_mode', true);
    }

    public function test_switching_off_needs_no_password_and_repeating_it_logs_nothing(): void
    {
        $token = $this->developerToken();

        $this->mode($token, ['on' => false])->assertOk()->assertJsonPath('employee.developer_mode', false);
        $this->mode($token, ['on' => false])->assertOk();

        $this->assertSame(1, PortalLog::query()->where('employee_id', $this->developer->id)->where('message', 'like', '%switched off Developer mode')->count());
    }

    public function test_the_password_changes_only_in_developer_mode_and_is_never_logged(): void
    {
        $token = $this->developerToken();
        $default = $this->login($this->developer);

        $this->password($default, self::NEW_PASSWORD, 'another-pass-7')->assertForbidden();
        $this->password($token, 'wrong-password', 'another-pass-7')->assertStatus(422);
        $this->password($token, self::NEW_PASSWORD, 'another-pass-7')->assertOk();
        $this->mode($default, ['on' => true, 'password' => self::NEW_PASSWORD])->assertStatus(422);
        $this->mode($default, ['on' => true, 'password' => 'another-pass-7'])->assertOk();

        $logs = PortalLog::query()->where('employee_id', $this->developer->id)->where('resource', 'like', 'developer.%')->get();
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $text = $log->message.' '.json_encode($log->payload);
            $this->assertStringNotContainsString(self::NEW_PASSWORD, $text);
            $this->assertStringNotContainsString('another-pass-7', $text);
        }
    }

    public function test_password_guesses_are_held_to_five_a_minute(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        Cache::flush();
        $token = $this->login($this->developer);

        foreach (range(1, 5) as $attempt) {
            $this->mode($token, ['on' => true, 'password' => 'guess-'.$attempt])->assertStatus(422);
        }
        $this->mode($token, ['on' => true, 'password' => 'guess-6'])->assertStatus(429);
    }

    // Nobody else learns the feature exists.
    public function test_anyone_but_a_developer_is_answered_not_found(): void
    {
        $token = $this->login($this->member);

        $this->mode($token, ['on' => true, 'password' => '123'])->assertNotFound();
        $this->password($token, '123', self::NEW_PASSWORD)->assertNotFound();
    }

    // In Default a developer is their role: an admin can limit them, and Developer mode ignores it.
    public function test_default_follows_their_rules_and_developer_mode_ignores_them(): void
    {
        $adminToken = $this->login($this->admin);
        $this->assertTrue($this->editable($adminToken)[$this->developer->id]);
        $this->withToken($adminToken)
            ->patchJson("/api/development/portal/manage/access/{$this->developer->id}", ['key' => 'reports', 'action' => 'off'])
            ->assertOk();

        $default = $this->login($this->developer);
        $this->powers($default)->assertJsonPath('tier', 'member');
        $this->assertNotContains('reports', $this->keys($this->powers($default)));
        $this->withToken($default)->getJson('/api/development/portal/manage/requests')->assertForbidden();

        $token = $this->developerToken();
        $this->powers($token)->assertJsonPath('tier', 'developer');
        $this->assertContains('reports', $this->keys($this->powers($token)));
        $this->withToken($token)->getJson('/api/development/portal/manage/requests')->assertOk();
    }

    // Developer mode keeps them able to put the role back, so only then may they remove their own.
    public function test_a_developer_removes_their_own_admin_role_only_in_developer_mode(): void
    {
        $this->developer->forceFill(['role' => 'Admin'])->save();
        $body = ['role' => 'User'];

        $this->withToken($this->login($this->developer))
            ->patchJson("/api/development/portal/manage/users/{$this->developer->id}/role", $body)
            ->assertForbidden();
        $this->withToken($this->developerToken())
            ->patchJson("/api/development/portal/manage/users/{$this->developer->id}/role", $body)
            ->assertOk();
    }

    // A gift made in Developer mode is a developer's gift, so signing out of the mode keeps it.
    public function test_a_gift_made_in_developer_mode_outlasts_the_mode(): void
    {
        $token = $this->developerToken();
        $this->withToken($token)
            ->patchJson("/api/development/portal/manage/access/{$this->member->id}", ['key' => 'administration', 'action' => 'on', 'reach' => 'all'])
            ->assertOk();
        $this->mode($token, ['on' => false])->assertOk();

        $this->assertContains('administration', $this->keys($this->powers($this->login($this->member))));
    }

    private function developerToken(): string
    {
        $token = $this->login($this->developer);
        $this->mode($token, ['on' => true, 'password' => '123', 'newPassword' => self::NEW_PASSWORD])->assertOk();

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mode(string $token, array $body): TestResponse
    {
        return $this->withToken($token)->patchJson('/api/development/portal/developer/mode', $body);
    }

    private function password(string $token, string $current, string $new): TestResponse
    {
        return $this->withToken($token)->patchJson('/api/development/portal/developer/password', ['current' => $current, 'new' => $new]);
    }

    private function me(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/development/portal/auth/me')->assertOk();
    }

    private function powers(string $token): TestResponse
    {
        return $this->withToken($token)->getJson('/api/development/portal/access')->assertOk();
    }

    /**
     * @return list<string>
     */
    private function keys(TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('key')->all();
    }

    /**
     * @return array<int, bool>
     */
    private function editable(string $token): array
    {
        $rows = $this->withToken($token)->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['canEditAccess']])->all();
    }

    private function login(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    /**
     * @return list<Employee>
     */
    private function active(string $where, int $count): array
    {
        $employees = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->all();
        $this->assertCount($count, $employees);

        return $employees;
    }
}
