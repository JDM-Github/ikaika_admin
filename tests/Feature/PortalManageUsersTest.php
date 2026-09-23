<?php

namespace Tests\Feature;

use App\Mail\PortalAccessAlertMail;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalAccessDenied;
use App\Support\Portal\PortalManageUserPresenter;
use App\Support\Portal\PortalRole;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalManageUsersTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    public function test_catalog_lists_manage_users_under_portal_sections(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.3.name', 'manage')
            ->assertJsonPath('sections.3.resources.0.name', 'users')
            ->assertJsonPath('sections.3.resources.0.url', '/api/development/portal/manage/users');
    }

    public function test_the_roster_is_skinny_and_includes_counts(): void
    {
        $token = $this->tokenForAdmin();

        $response = $this->getJson('/api/development/portal/manage/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJsonPath('section', 'manage')
            ->assertJsonPath('resource', 'users')
            ->assertJsonStructure([
                'data' => [
                    ['id', 'id_no', 'first_name', 'last_name', 'email', 'department', 'job_title', 'status', 'role', 'role_level', 'is_admin', 'is_executive', 'is_locked'],
                ],
                'counts' => ['admins', 'members', 'active', 'inactive', 'total'],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $first = $response->json('data.0');
        $this->assertIsArray($first);
        $this->assertSame(PortalManageUserPresenter::rosterColumns(), array_values(array_diff(array_keys($first), ['is_admin', 'is_executive', 'is_locked'])));
        $this->assertArrayNotHasKey('bank_account_number', $first);
        $this->assertArrayNotHasKey('tax_identification_no', $first);
        $this->assertArrayNotHasKey('personal_email', $first);
        $this->assertArrayNotHasKey('phone_number', $first);
        $this->assertArrayNotHasKey('address', $first);
        $this->assertGreaterThan(0, $response->json('counts.total'));
        $this->assertSame(25, $response->json('meta.per_page'));
        $this->assertSame(1, $response->json('meta.current_page'));
    }

    public function test_the_roster_uses_allow_listed_page_sizes(): void
    {
        $token = $this->tokenForAdmin();
        $headers = ['Authorization' => "Bearer {$token}"];

        $ten = $this->getJson('/api/development/portal/manage/users?per_page=10', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10);

        $this->assertLessThanOrEqual(10, count($ten->json('data')));

        $this->getJson('/api/development/portal/manage/users?per_page=7', $headers)
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_search_requires_every_word_and_sorts_when_asked(): void
    {
        $token = $this->tokenForAdmin();
        $headers = ['Authorization' => "Bearer {$token}"];
        $employee = Employee::query()
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        $this->getJson('/api/development/portal/manage/users?per_page=10&q='.urlencode((string) $employee->id_no), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $employee->id);

        $this->getJson('/api/development/portal/manage/users?q='.urlencode($employee->last_name.' zzznomatchxyz'), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $sorted = $this->getJson('/api/development/portal/manage/users?per_page=10&sort=email&dir=desc', $headers)
            ->assertOk();

        $first = $sorted->json('data.0.email');
        $second = $sorted->json('data.1.email');
        $this->assertIsString($first);
        if (is_string($second)) {
            $this->assertLessThanOrEqual(0, strcasecmp($second, $first));
        }
    }

    public function test_executives_are_locked_on_the_roster(): void
    {
        $executive = $this->executive();
        $token = $this->tokenForAdmin();

        $this->getJson('/api/development/portal/manage/users?per_page=100', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonFragment([
                'id' => $executive->id,
                'is_executive' => true,
                'is_locked' => true,
                'is_admin' => true,
            ]);
    }

    public function test_a_member_cannot_read_the_roster(): void
    {
        $this->giveAlertMailbox($this->adminWhoIsNotExecutive());
        $token = $this->tokenForMember();

        $this->getJson('/api/development/portal/manage/users', [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.')
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_NOTIFIED)
            ->assertJsonPath('notified', true);

        Mail::assertSent(PortalAccessAlertMail::class);
    }

    public function test_the_roster_requires_a_token(): void
    {
        $this->getJson('/api/development/portal/manage/users')
            ->assertUnauthorized()
            ->assertJsonMissingPath('warning');

        Mail::assertNothingSent();
    }

    public function test_an_admin_can_promote_a_member(): void
    {
        $member = $this->promotableMember();
        $previousRole = $member->role;
        $admin = $this->adminWhoIsNotExecutive();
        $token = $this->loginToken($admin);

        $this->patchJson("/api/development/portal/manage/users/{$member->id}/role", [
            'role' => PortalRole::ADMIN,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.role', PortalRole::ADMIN)
            ->assertJsonPath('data.is_admin', true);

        $this->assertSame(PortalRole::ADMIN, $member->fresh()?->role);

        $memberName = trim(trim((string) $member->first_name).' '.trim((string) $member->last_name));
        $adminName = trim(trim((string) $admin->first_name).' '.trim((string) $admin->last_name));
        if ($memberName === '') {
            $memberName = 'Member';
        }
        if ($adminName === '') {
            $adminName = 'Member';
        }

        $actorLog = PortalLog::query()
            ->where('employee_id', $admin->id)
            ->where('resource', 'manage.users')
            ->where('record_id', (string) $member->id)
            ->orderByDesc('id')
            ->first();
        $targetLog = PortalLog::query()
            ->where('employee_id', $member->id)
            ->where('resource', 'manage.users')
            ->where('record_id', (string) $member->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($actorLog);
        $this->assertNotNull($targetLog);
        $this->assertSame('PATCH', $actorLog->action);
        $this->assertSame("{UserName|You} changed {$memberName}'s role to Admin", $actorLog->message);
        $this->assertSame("{UserName|Your} role was changed to Admin by {$adminName}", $targetLog->message);
        $this->assertIsArray($actorLog->payload);
        $this->assertSame($previousRole, $actorLog->payload['previousRole']);
        $this->assertSame(PortalRole::ADMIN, $actorLog->payload['newRole']);
        $this->assertIsArray($targetLog->payload);
        $this->assertSame($previousRole, $targetLog->payload['previousRole']);
        $this->assertSame(PortalRole::ADMIN, $targetLog->payload['newRole']);

        $inbox = PortalNotification::query()
            ->where('employee_id', $member->id)
            ->where('type', 'manage.users.role')
            ->where('actor_id', $admin->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($inbox);
        $this->assertSame("Your portal role was changed to Admin by {$adminName}.", $inbox->message);
    }

    public function test_an_admin_can_set_project_admin(): void
    {
        $member = $this->promotableMember();
        $token = $this->tokenForAdmin();

        $this->patchJson("/api/development/portal/manage/users/{$member->id}/role", [
            'role' => PortalRole::PROJECT_ADMIN,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.role', PortalRole::PROJECT_ADMIN)
            ->assertJsonPath('data.is_admin', false);

        $this->assertSame(PortalRole::PROJECT_ADMIN, $member->fresh()?->role);
    }

    public function test_an_admin_can_demote_another_admin(): void
    {
        $actor = $this->adminWhoIsNotExecutive();
        $target = $this->otherAdmin($actor);
        $token = $this->loginToken($actor);

        $this->patchJson("/api/development/portal/manage/users/{$target->id}/role", [
            'role' => PortalRole::USER,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.role', PortalRole::USER)
            ->assertJsonPath('data.is_admin', false);
    }

    public function test_an_executive_role_cannot_be_changed(): void
    {
        $executive = $this->executive();
        $actor = $this->adminWhoIsNotExecutive();
        $this->giveAlertMailbox($this->otherAdmin($actor));
        $token = $this->loginToken($actor);

        $this->patchJson("/api/development/portal/manage/users/{$executive->id}/role", [
            'role' => PortalRole::USER,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden()
            ->assertJsonPath('message', 'The executive role cannot be changed.')
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_NOTIFIED)
            ->assertJsonPath('notified', true);

        $this->assertSame($executive->role, $executive->fresh()?->role);
        Mail::assertSent(PortalAccessAlertMail::class);
    }

    public function test_an_admin_cannot_remove_their_own_role(): void
    {
        $admin = $this->adminWhoIsNotExecutive();
        $token = $this->loginToken($admin);

        $this->patchJson("/api/development/portal/manage/users/{$admin->id}/role", [
            'role' => PortalRole::USER,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden()
            ->assertJsonPath('message', 'You cannot remove your own administrator role.')
            ->assertJsonMissingPath('warning');

        Mail::assertNothingSent();
    }

    public function test_executive_cannot_be_assigned_through_this_endpoint(): void
    {
        $member = $this->promotableMember();
        $token = $this->tokenForAdmin();

        $this->patchJson("/api/development/portal/manage/users/{$member->id}/role", [
            'role' => 'Executive',
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertUnprocessable();
    }

    public function test_a_member_cannot_set_someone_admin(): void
    {
        $this->giveAlertMailbox($this->adminWhoIsNotExecutive());
        $member = $this->promotableMember();
        $token = $this->tokenForMember();

        $this->patchJson("/api/development/portal/manage/users/{$member->id}/role", [
            'role' => PortalRole::ADMIN,
        ], [
            'Authorization' => "Bearer {$token}",
        ])->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.')
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_NOTIFIED);

        Mail::assertSent(PortalAccessAlertMail::class);
    }

    public function test_a_repeat_probe_is_logged_without_a_second_mail(): void
    {
        $this->giveAlertMailbox($this->adminWhoIsNotExecutive());
        $token = $this->tokenForMember();
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/development/portal/manage/users', $headers)
            ->assertForbidden()
            ->assertJsonPath('notified', true);

        $this->getJson('/api/development/portal/manage/users', $headers)
            ->assertForbidden()
            ->assertJsonPath('notified', false)
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_RECORDED);

        Mail::assertSent(PortalAccessAlertMail::class, 1);
    }

    private function tokenForAdmin(): string
    {
        return $this->loginToken($this->adminWhoIsNotExecutive());
    }

    private function tokenForMember(): string
    {
        $member = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($member);

        return $this->loginToken($member);
    }

    private function loginToken(Employee $employee): string
    {
        $this->assertNotEmpty($employee->id_no);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function executive(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function adminWhoIsNotExecutive(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function otherAdmin(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->where('id', '!=', $actor->id)
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function promotableMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function giveAlertMailbox(Employee $employee, string $email = 'alerts@example.test'): void
    {
        $employee->forceFill(['email' => $email])->save();
    }
}
