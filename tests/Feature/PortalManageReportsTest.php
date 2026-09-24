<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAccessDenied;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PortalManageReportsTest extends TestCase
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

    public function test_catalog_lists_manage_reports_under_portal_sections(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.3.name', 'manage')
            ->assertJsonPath('sections.3.resources.2.name', 'reports')
            ->assertJsonPath('sections.3.resources.2.url', '/api/development/portal/manage/reports');
    }

    public function test_an_admin_reads_another_member_s_timesheets_and_the_roster(): void
    {
        $subject = $this->activeMember();
        $other = $this->otherActiveMember($subject);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $this->insertLine($subject, [
            'report_date' => '2026-08-20',
            'hours_rendered' => 8,
            'remarks' => 'SUBJECT-ONLY-LINE',
        ], $project, $activity, $earn);
        $this->insertLine($other, [
            'report_date' => '2026-08-21',
            'hours_rendered' => 8,
            'remarks' => 'OTHER-ONLY-LINE',
        ], $project, $activity, $earn);

        $token = $this->tokenForAdmin();
        $response = $this->getJson(
            '/api/development/portal/manage/reports?from=2026-08-01&to=2026-08-31&employee_id='.$subject->id,
            ['Authorization' => "Bearer {$token}"],
        );

        $response->assertOk()
            ->assertJsonPath('section', 'manage')
            ->assertJsonPath('resource', 'reports')
            ->assertJsonPath('employeeId', (string) $subject->id);

        $ids = $response->json('data.*.id');
        $this->assertIsArray($ids);
        $this->assertContains('2026-08-20-daily', $ids);
        $this->assertNotContains('2026-08-21-daily', $ids);

        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringContainsString('SUBJECT-ONLY-LINE', $encoded);
        $this->assertStringNotContainsString('OTHER-ONLY-LINE', $encoded);

        $employees = $response->json('employees');
        $this->assertIsArray($employees);
        $this->assertNotEmpty($employees);
        $found = collect($employees)->firstWhere('value', (string) $subject->id);
        $this->assertIsArray($found);
        $this->assertSame(
            PortalSubmittedReportPresenter::memberName(
                is_string($subject->first_name) ? $subject->first_name : null,
                is_string($subject->last_name) ? $subject->last_name : null,
            ),
            $found['label'],
        );
        $this->assertArrayNotHasKey('bank_account_number', $found);
    }

    public function test_omitting_employee_id_opens_the_signed_in_admin(): void
    {
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($admin);

        $token = $this->loginToken($admin);
        $this->getJson('/api/development/portal/manage/reports?from=2026-08-01&to=2026-08-31', [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('employeeId', (string) $admin->id);
    }

    public function test_an_unknown_member_is_not_found(): void
    {
        $this->getJson('/api/development/portal/manage/reports?employee_id=999999999', [
            'Authorization' => 'Bearer '.$this->tokenForAdmin(),
        ])->assertNotFound();
    }

    public function test_a_member_cannot_read_managed_reports(): void
    {
        $this->getJson('/api/development/portal/manage/reports', [
            'Authorization' => 'Bearer '.$this->tokenForMember(),
        ])
            ->assertForbidden()
            ->assertJsonPath('warning', PortalAccessDenied::WARNING_NOTIFIED);
    }

    public function test_managed_reports_require_a_bearer_token(): void
    {
        $this->getJson('/api/development/portal/manage/reports')->assertUnauthorized();
    }

    public function test_a_department_admin_opens_only_their_own_department(): void
    {
        $inside = $this->activeMember();
        $outside = $this->otherActiveMember($inside);
        $inside->forceFill(['department' => ' mis '])->save();
        $outside->forceFill(['department' => 'Engineering'])->save();
        $admin = $this->departmentAdmin();
        $token = $this->loginToken($admin);

        $roster = $this->withToken($token)
            ->getJson('/api/development/portal/manage/reports?employee_id='.$inside->id)
            ->assertOk()
            ->json('employees.*.value');
        $this->assertIsArray($roster);
        $this->assertContains((string) $inside->id, $roster);
        $this->assertContains((string) $admin->id, $roster);
        $this->assertNotContains((string) $outside->id, $roster);

        $this->withToken($token)
            ->getJson('/api/development/portal/manage/reports?employee_id='.$outside->id)
            ->assertNotFound();
    }

    // Previewing as Admin holds a developer to their department; their real view opens everyone.
    public function test_a_developer_previewing_as_admin_is_held_to_their_department(): void
    {
        $outside = $this->activeMember();
        $outside->forceFill(['department' => 'Engineering'])->save();
        $token = $this->loginToken($this->departmentAdmin(developer: true));

        $roster = $this->withToken($token)
            ->getJson('/api/development/portal/manage/reports?employee_id='.$outside->id)
            ->assertOk()
            ->json('employees.*.value');
        $this->assertIsArray($roster);
        $this->assertContains((string) $outside->id, $roster);

        $this->withToken($token)->withHeaders(['X-Portal-View' => 'admin'])
            ->getJson('/api/development/portal/manage/reports?employee_id='.$outside->id)
            ->assertNotFound();
    }

    // An admin with no department still opens their own timesheets and nobody else's.
    public function test_an_admin_with_no_department_opens_only_themself(): void
    {
        $admin = $this->departmentAdmin();
        $admin->forceFill(['department' => null])->save();

        $roster = $this->withToken($this->loginToken($admin))
            ->getJson('/api/development/portal/manage/reports')
            ->assertOk()
            ->json('employees.*.value');

        $this->assertSame([(string) $admin->id], $roster);
    }

    // Executives are managed from their own portal: not even a developer lists or opens them.
    public function test_an_executive_is_never_listed_or_opened(): void
    {
        $executive = $this->executive();
        $token = $this->loginToken($this->departmentAdmin(developer: true));

        $roster = $this->withToken($token)
            ->getJson('/api/development/portal/manage/reports')
            ->assertOk()
            ->json('employees.*.value');
        $this->assertIsArray($roster);
        $this->assertNotContains((string) $executive->id, $roster);

        $this->withToken($token)
            ->getJson('/api/development/portal/manage/reports?employee_id='.$executive->id)
            ->assertNotFound();
    }

    // An executive who opens this page still reads their own timesheets.
    public function test_an_executive_still_opens_their_own_timesheets(): void
    {
        $executive = $this->executive();

        $this->withToken($this->loginToken($executive))
            ->getJson('/api/development/portal/manage/reports')
            ->assertOk()
            ->assertJsonPath('employeeId', (string) $executive->id)
            ->assertJsonPath('employees.0.value', (string) $executive->id);
    }

    private function executive(): Employee
    {
        $executive = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($executive);
        $executive->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        return $executive;
    }

    // A developer sees every department, so tests about the page itself do not depend on who is where.
    private function tokenForAdmin(): string
    {
        return $this->loginToken($this->departmentAdmin(developer: true));
    }

    private function departmentAdmin(bool $developer = false): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => $developer, 'department' => 'MIS'])->save();

        return $employee;
    }

    private function tokenForMember(): string
    {
        return $this->loginToken($this->activeMember());
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

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->where('id', '!=', $actor->id)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function firstProject(): object
    {
        $row = DB::connection('portal')->table('projects')->whereNotNull('project_name')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function firstActivity(): object
    {
        $row = DB::connection('portal')->table('activity_codes')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function firstEarnCode(): object
    {
        $row = DB::connection('portal')->table('earn_codes')->first();
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertLine(
        Employee $employee,
        array $attributes,
        object $project,
        object $activity,
        object $earn,
    ): int {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $attributes['report_date'],
            'hours_rendered' => $attributes['hours_rendered'] ?? 8,
            'change_in_elements' => $attributes['change_in_elements'] ?? 0,
            'remarks' => $attributes['remarks'] ?? null,
            'late_submission' => $attributes['late_submission'] ?? null,
            'approval' => 'Approved',
        ]);

        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $id,
        ]);
        DB::connection('portal')->table('projects_user_reports')->insert([
            'project_id' => $project->id,
            'user_report_id' => $id,
        ]);
        DB::connection('portal')->table('user_reports_activity_codes')->insert([
            'user_report_id' => $id,
            'activity_code_id' => $activity->id,
        ]);
        DB::connection('portal')->table('user_reports_earn_codes')->insert([
            'user_report_id' => $id,
            'earn_code_id' => $earn->id,
        ]);

        return $id;
    }
}
