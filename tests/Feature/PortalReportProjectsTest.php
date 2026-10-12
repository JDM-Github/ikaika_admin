<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectApproval;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalReportProjectsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_member_sees_only_the_projects_they_are_assigned_to(): void
    {
        $actor = $this->activeMember();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $own = $this->ownProjectIds($actor);
        $this->assertNotEmpty($own);
        $unassigned = DB::connection('portal')->table('projects')
            ->whereNotIn('id', $own)
            ->whereNotNull('project_name')
            ->first();
        $this->assertNotNull($unassigned);

        $response = $this->getJson('/api/development/portal/reports/projects', $headers)
            ->assertOk()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'projects');

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotEmpty($ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            $this->assertContains((int) $id, $own);
        }
        $this->assertNotContains((string) $unassigned->id, $ids);
        $this->assertNotContains(
            PortalSubmittedReportPresenter::projectLabel(
                $unassigned->project_number ?? null,
                $unassigned->project_name ?? null,
            ),
            array_column($response->json('data'), 'label'),
        );
    }

    // The reported bug: a project still waiting for approval, or turned down, was offered to report on.
    public function test_a_project_is_offered_only_once_it_is_approved(): void
    {
        $actor = $this->activeMember();
        $ids = [];
        foreach ([PortalProjectApproval::APPROVED, PortalProjectApproval::PENDING, PortalProjectApproval::REJECTED, null] as $status) {
            $ids[$status ?? 'none'] = (string) $this->assignedProject($actor, [
                'project_name' => 'Approval '.Str::random(8),
                'project_creation_status' => $status,
            ]);
        }

        $offered = array_column($this->getJson('/api/development/portal/reports/projects', [
            'Authorization' => 'Bearer '.$this->loginToken($actor),
        ])->assertOk()->json('data'), 'id');

        $this->assertContains($ids[PortalProjectApproval::APPROVED], $offered);
        $this->assertContains($ids['none'], $offered);
        $this->assertNotContains($ids[PortalProjectApproval::PENDING], $offered);
        $this->assertNotContains($ids[PortalProjectApproval::REJECTED], $offered);
    }

    // The reported bug: a project added in the portal keeps its number in its name, so it read "260007 260007 Test".
    public function test_a_project_number_is_said_once_even_when_the_name_leads_with_it(): void
    {
        $actor = $this->activeMember();
        $id = $this->assignedProject($actor, [
            'project_number' => '269907',
            'project_name' => '269907 Doubled '.Str::random(6),
            'project_creation_status' => PortalProjectApproval::APPROVED,
        ]);
        $name = (string) DB::connection('portal')->table('projects')->where('id', $id)->value('project_name');

        $labels = array_column($this->getJson('/api/development/portal/reports/projects', [
            'Authorization' => 'Bearer '.$this->loginToken($actor),
        ])->assertOk()->json('data'), 'label', 'id');

        $this->assertSame($name, $labels[(string) $id]);
        $this->assertSame('22204 Morrow', PortalSubmittedReportPresenter::projectLabel('22204', 'Morrow'));
        $this->assertSame('26 260007 Test', PortalSubmittedReportPresenter::projectLabel('26', '260007 Test'));
        $this->assertSame('260007', PortalSubmittedReportPresenter::projectLabel('260007', '260007'));
        $this->assertSame('Unassigned', PortalSubmittedReportPresenter::projectLabel(null, ' '));
    }

    // A report asks for lines only on an Engineering project: each project says whether it does.
    public function test_each_project_says_whether_its_reports_carry_lines(): void
    {
        $actor = $this->activeMember();
        $projectId = (int) DB::connection('portal')->table('employees_projects')->where('employee_id', $actor->getKey())->value('project_id');
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        DB::connection('portal')->table('projects')->where('id', $projectId)->update(['department' => 'MIS']);
        Cache::flush();
        $mis = collect($this->getJson('/api/development/portal/reports/projects', $headers)->assertOk()->json('data'))->firstWhere('id', (string) $projectId);
        DB::connection('portal')->table('projects')->where('id', $projectId)->update(['department' => 'Engineering']);
        Cache::flush();
        $engineering = collect($this->getJson('/api/development/portal/reports/projects', $headers)->assertOk()->json('data'))->firstWhere('id', (string) $projectId);

        $this->assertFalse($mis['hasLines']);
        $this->assertTrue($engineering['hasLines']);
    }

    public function test_activities_are_grouped_by_job_type_rather_than_repeated_per_project(): void
    {
        $actor = $this->activeMember();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $response = $this->getJson('/api/development/portal/reports/projects', $headers)->assertOk();

        $groups = $response->json('activityGroups');
        $this->assertIsArray($groups);
        $this->assertNotEmpty($groups);

        $jobTypes = array_column($response->json('data'), 'jobType');
        foreach ($groups as $group) {
            $this->assertArrayHasKey('jobType', $group);
            $this->assertArrayHasKey('activities', $group);
            $this->assertNotEmpty($group['activities']);
            if ($group['jobType'] !== null) {
                $this->assertContains($group['jobType'], $jobTypes);
            }
        }

        // One group per job type, never one per project.
        $named = array_filter(array_column($groups, 'jobType'), static fn (?string $type): bool => $type !== null);
        $this->assertSame(array_values($named), array_values(array_unique($named)));
        $this->assertLessThanOrEqual(count($response->json('data')), count($groups));
    }

    public function test_a_job_type_only_offers_the_activity_codes_its_initials_allow(): void
    {
        $actor = $this->activeMember();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $rule = DB::connection('portal')->table('job_type_allowed_activities')
            ->whereNotNull('activity_code_id_initials')
            ->where('activity_code_id_initials', '!=', '')
            ->first();
        $this->assertNotNull($rule);

        $project = DB::connection('portal')->table('projects')
            ->where('type_of_job', $rule->job_type)
            ->whereNotNull('project_name')
            ->first();
        $this->assertNotNull($project);
        DB::connection('portal')->table('employees_projects')->insertOrIgnore([
            'employee_id' => $actor->getKey(),
            'project_id' => $project->id,
            'role_on_project' => 'member',
        ]);

        $response = $this->getJson('/api/development/portal/reports/projects', $headers)->assertOk();

        $group = collect($response->json('activityGroups'))->firstWhere('jobType', $rule->job_type);
        $this->assertIsArray($group);

        $initials = array_map('trim', explode(',', (string) $rule->activity_code_id_initials));
        $expected = DB::connection('portal')->table('activity_codes')
            ->where(function ($query) use ($initials): void {
                foreach ($initials as $prefix) {
                    $query->orWhere('id_no', 'like', $prefix.'%');
                }
            })
            ->orderBy('id_no')
            ->get()
            ->map(static fn (object $row): string => PortalSubmittedReportPresenter::activityLabel(
                $row->name ?? null,
                $row->id_no ?? null,
            ))
            ->all();

        $this->assertSame($expected, $group['activities']);
        $this->assertNotEmpty($expected);
        $everyCode = DB::connection('portal')->table('activity_codes')->count();
        $this->assertLessThan($everyCode, count($group['activities']));
    }

    public function test_a_job_type_with_no_rule_falls_back_to_the_whole_catalogue(): void
    {
        $actor = $this->activeMember();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $ruled = DB::connection('portal')->table('job_type_allowed_activities')->pluck('job_type')->all();
        $project = DB::connection('portal')->table('projects')
            ->whereNotNull('type_of_job')
            ->whereNotIn('type_of_job', $ruled)
            ->whereNotNull('project_name')
            ->first();
        $this->assertNotNull($project);
        DB::connection('portal')->table('employees_projects')->insertOrIgnore([
            'employee_id' => $actor->getKey(),
            'project_id' => $project->id,
            'role_on_project' => 'member',
        ]);

        $response = $this->getJson('/api/development/portal/reports/projects', $headers)->assertOk();

        $fallback = collect($response->json('activityGroups'))->firstWhere('jobType', null);
        $this->assertIsArray($fallback);
        $this->assertCount(
            DB::connection('portal')->table('activity_codes')->count(),
            $fallback['activities'],
        );
    }

    public function test_the_picker_is_three_small_reads_and_needs_a_token(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);

        $this->getJson('/api/development/portal/reports/projects')->assertUnauthorized();

        $queries = [];
        DB::connection('portal')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/development/portal/reports/projects', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();

        $payload = array_values(array_filter(
            $this->withoutRuleCheck($queries),
            static fn (string $sql): bool => ! str_contains($sql, 'from `employees` '),
        ));
        $this->assertLessThanOrEqual(3, count($payload));
        $this->assertSame(0, $this->countContaining($payload, 'user_reports'));
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

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function assignedProject(Employee $employee, array $columns): int
    {
        $projectId = (int) DB::connection('portal')->table('projects')->insertGetId($columns);
        DB::connection('portal')->table('employees_projects')->insert([
            'employee_id' => $employee->getKey(),
            'project_id' => $projectId,
            'role_on_project' => 'member',
        ]);

        return $projectId;
    }

    /**
     * @return list<int>
     */
    private function ownProjectIds(Employee $employee): array
    {
        return DB::connection('portal')->table('employees_projects')
            ->where('employee_id', $employee->getKey())
            ->distinct()
            ->pluck('project_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<string>  $queries
     */
    private function countContaining(array $queries, string $needle): int
    {
        return count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, $needle)));
    }
}
