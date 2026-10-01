<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Activity tab: every report on a project, newest first. The lead, a covering Project Manager and
 * Admin or higher see everyone's hours and remarks; another member sees who reported and their own
 * reports in full; someone View Projects does not reach is refused.
 */
class PortalProjectActivityTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/projects';

    private int $projectId;

    private Employee $lead;

    private Employee $member;

    private Employee $outsider;

    private int $code;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        $portal = DB::connection('portal');
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            $portal->table($table)->delete();
        }
        $this->lead = $this->employee(0);
        $this->member = $this->employee(1);
        $this->outsider = $this->employee(2);
        $this->projectId = (int) $portal->table('projects')->insertGetId([
            'project_name' => 'Activity Test 2020', 'department' => 'Engineering', 'status' => 'Started', 'project_lead_email' => $this->lead->email,
        ]);
        $portal->table('employees_projects')->insert(['employee_id' => $this->member->getKey(), 'project_id' => $this->projectId, 'role_on_project' => 'member']);
        $this->code = (int) $portal->table('activity_codes')->insertGetId(['id_no' => '9811', 'name' => '9811- TEST CHECKING', 'department' => 'Engineering']);
        $other = (int) $portal->table('activity_codes')->insertGetId(['id_no' => '9812', 'name' => 'Other work', 'department' => 'Engineering']);

        $this->report($this->lead, '2026-09-01', 8, $this->code, 'Lead remark');
        $this->report($this->lead, '2026-09-03', 4, $other, null);
        $this->report($this->member, '2026-09-02', 6, $this->code, 'Member remark', ['late_submission' => 'YES']);
        $this->report($this->member, '2026-09-04', 99, $this->code, 'Rejected', ['approval' => 'Rejected']);
    }

    private function employee(int $skip): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')->skip($skip)->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function admin(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function report(Employee $person, string $day, float $hours, int $code, ?string $remarks, array $extra = []): void
    {
        $portal = DB::connection('portal');
        $id = (int) $portal->table('user_reports')->insertGetId([
            'report_date' => $day, 'actual_work_date' => $day, 'hours_rendered' => $hours, 'change_in_elements' => 3,
            'remarks' => $remarks, 'approval' => 'Approved', 'late_submission' => 'NO', 'late_submission_approval' => 'N/A', ...$extra,
        ]);
        $portal->table('projects_user_reports')->insert(['project_id' => $this->projectId, 'user_report_id' => $id]);
        $portal->table('employees_user_reports')->insert(['employee_id' => $person->getKey(), 'user_report_id' => $id]);
        $portal->table('user_reports_activity_codes')->insert(['user_report_id' => $id, 'activity_code_id' => $code]);
    }

    private function activity(Employee $viewer, string $query = ''): TestResponse
    {
        $token = (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $viewer->id_no])->assertOk()->json('token');

        return $this->withToken($token)->getJson(self::BASE.'/'.$this->projectId.'/activity'.$query);
    }

    public function test_an_admin_sees_every_report_with_hours_remarks_and_lines(): void
    {
        $data = $this->activity($this->admin())->assertOk()->json('data');

        $this->assertTrue($data['seesEveryone']);
        $this->assertSame(3, $data['total']);
        $this->assertSame(['2026-09-03', '2026-09-02', '2026-09-01'], array_column($data['reports'], 'workDay'));
        $this->assertSame(['Member remark', true, 3, 'TEST CHECKING'], [$data['reports'][1]['remarks'], $data['reports'][1]['isLate'], $data['reports'][1]['lines'], $data['reports'][1]['activityName']]);
        $people = collect($data['people'])->keyBy('id');
        $this->assertEquals([12, 2, true, false], [$people[(string) $this->lead->getKey()]['hours'], $people[(string) $this->lead->getKey()]['reports'], $people[(string) $this->lead->getKey()]['isLead'], $people[(string) $this->lead->getKey()]['isMember']]);
        $this->assertSame(['9811', '9812'], array_column($data['activities'], 'code'));
    }

    // Another member sees who reported, but only their own hours and reports.
    public function test_a_member_sees_their_own_work_and_only_who_else_reported(): void
    {
        $data = $this->activity($this->member)->assertOk()->json('data');

        $this->assertFalse($data['seesEveryone']);
        $this->assertSame(1, $data['total']);
        $this->assertSame('Member remark', $data['reports'][0]['remarks']);
        $people = collect($data['people'])->keyBy('id');
        $this->assertSame([true, null, null, null], [$people[(string) $this->lead->getKey()]['hasReported'], $people[(string) $this->lead->getKey()]['hours'], $people[(string) $this->lead->getKey()]['reports'], $people[(string) $this->lead->getKey()]['lastReportOn']]);
        $this->assertEquals(6, $people[(string) $this->member->getKey()]['hours']);
        $this->assertSame(['9811'], array_column($data['activities'], 'code'));
    }

    public function test_the_lead_sees_everyone_and_filters_and_pages_the_feed(): void
    {
        $this->assertTrue($this->activity($this->lead)->assertOk()->json('data.seesEveryone'));

        $byPerson = $this->activity($this->lead, '?person='.$this->member->getKey())->json('data');
        $this->assertSame(1, $byPerson['total']);
        $this->assertSame(2, $this->activity($this->lead, '?activity=9811')->json('data.total'));
        $this->assertSame([], $this->activity($this->lead, '?page=2')->json('data.reports'));
    }

    // A member View Projects does not reach (own reach, not on the project) is refused; no project is a 404.
    public function test_someone_the_project_is_out_of_reach_for_is_refused(): void
    {
        $this->activity($this->outsider)->assertForbidden();

        $token = (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $this->admin()->id_no])->json('token');
        $this->withToken($token)->getJson(self::BASE.'/999999999/activity')->assertNotFound();
    }

    // A member with no reports is listed so the lead sees who has not reported yet.
    public function test_members_without_reports_are_listed(): void
    {
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $this->outsider->getKey(), 'project_id' => $this->projectId, 'role_on_project' => 'member']);

        $person = collect($this->activity($this->lead)->json('data.people'))->firstWhere('id', (string) $this->outsider->getKey());

        $this->assertSame([false, true, null], [$person['hasReported'], $person['isMember'], $person['hours']]);
    }
}
