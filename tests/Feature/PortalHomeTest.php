<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalHome;
use App\Support\Portal\PortalSubmittedReports;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalHomeTest extends TestCase
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

    public function test_catalog_lists_home_dashboard_first(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.0.name', 'home')
            ->assertJsonPath('sections.0.resources.0.name', 'dashboard')
            ->assertJsonPath('sections.0.resources.0.url', '/api/development/portal/home')
            ->assertJsonPath('sections.0.requires_admin', false);
    }

    public function test_guest_cannot_read_the_dashboard(): void
    {
        $this->getJson('/api/development/portal/home')->assertStatus(401);
    }

    public function test_a_member_reads_a_skinny_dashboard_for_their_own_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', 'Asia/Manila'));

        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstAssignedProject($actor);
        $this->assertNotNull($project);

        $this->insertLine($actor, [
            'report_date' => '2026-08-17',
            'hours_rendered' => 8,
            'late_submission' => null,
        ], (int) $project->id);
        $this->insertLine($actor, [
            'report_date' => '2026-08-14',
            'hours_rendered' => 4,
            'late_submission' => 'Yes',
        ], (int) $project->id);
        $this->insertLine($other, [
            'report_date' => '2026-08-17',
            'hours_rendered' => 99,
            'late_submission' => null,
        ], (int) $project->id);

        $token = $this->loginToken($actor);
        $response = $this->withToken($token)
            ->withHeaders([PortalTimezone::NAME_HEADER => 'Asia/Manila'])
            ->getJson('/api/development/portal/home')
            ->assertOk()
            ->assertJsonPath('section', 'home')
            ->assertJsonPath('resource', 'dashboard')
            ->assertJsonPath('data.monthKey', '2026-08');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        // The seed already holds August filings for some members; assert ours landed and theirs did not.
        $this->assertGreaterThanOrEqual(12, (float) $payload['monthHours']);
        $this->assertGreaterThanOrEqual(2, (int) $payload['monthReportCount']);
        $this->assertArrayHasKey('activeProjectCount', $payload);
        $this->assertArrayHasKey('portfolioCompletion', $payload);
        $this->assertArrayHasKey('reportTrend', $payload);
        $this->assertArrayHasKey('projectMix', $payload);
        $this->assertArrayHasKey('recentReports', $payload);
        $this->assertArrayHasKey('trackedProjects', $payload);
        $this->assertGreaterThanOrEqual(1, (int) $payload['activeProjectCount']);
        $this->assertNotEmpty($payload['recentReports']);
        $dates = array_map(
            static fn (array $report): string => substr((string) ($report['submittedOn'] ?? ''), 0, 10),
            $payload['recentReports'],
        );
        $this->assertContains('2026-08-17', $dates);
        $this->assertArrayNotHasKey('bank', $payload);
        $this->assertSame(
            $actor->id_no,
            $payload['recentReports'][0]['referenceCode'] ?? null,
        );

        Carbon::setTestNow();
    }

    public function test_filing_a_report_bumps_the_home_cache(): void
    {
        $this->assertSame(1, (int) Cache::get(PortalHome::CACHE_VERSION_KEY, 1));

        app(PortalSubmittedReports::class)->bumpCache();

        $this->assertSame(2, (int) Cache::get(PortalHome::CACHE_VERSION_KEY, 1));
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereIn('id', function ($query): void {
                $query->select('employee_id')->from('employees_projects');
            })
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

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function firstAssignedProject(Employee $employee): object
    {
        $project = DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employee->getKey())
            ->select(['projects.id', 'projects.project_number', 'projects.project_name', 'projects.status'])
            ->first();
        $this->assertNotNull($project);

        return $project;
    }

    /**
     * @param  array{report_date: string, hours_rendered: float|int, late_submission?: ?string}  $line
     */
    private function insertLine(Employee $employee, array $line, int $projectId): void
    {
        $activity = DB::connection('portal')->table('activity_codes')->orderBy('id')->first();
        $this->assertNotNull($activity);

        $reportId = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $line['report_date'],
            'hours_rendered' => $line['hours_rendered'],
            'change_in_elements' => 0,
            'remarks' => null,
            'late_submission' => $line['late_submission'] ?? null,
            'approval' => 'Pending',
            'date_created' => $line['report_date'].' 09:00:00',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $reportId,
        ]);
        DB::connection('portal')->table('projects_user_reports')->insert([
            'project_id' => $projectId,
            'user_report_id' => $reportId,
        ]);
        DB::connection('portal')->table('user_reports_activity_codes')->insert([
            'activity_code_id' => $activity->id,
            'user_report_id' => $reportId,
        ]);
    }
}
