<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $queries,
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
