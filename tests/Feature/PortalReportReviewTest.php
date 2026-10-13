<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectApproval;
use App\Support\Portal\PortalReportReview;
use App\Support\Portal\PortalRole;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FilesPortalReports;
use Tests\TestCase;

// Daily report review (2026-10-07): Engineering's daily reports wait for their department's admin; MIS's are
// approved unless their author is flagged (2026-10-13).
class PortalReportReviewTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SUBMITTED = '/api/development/portal/reports/submitted';

    private const REVIEWS = '/api/development/portal/reports/reviews';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // The reported ask: every Engineering member is flagged, and MIS is approved even with no admin's answer.
    public function test_an_engineering_daily_report_waits_and_an_mis_one_is_approved_at_once(): void
    {
        $engineer = $this->memberOf('Engineering');
        $this->clearWarnings($engineer);
        $analyst = $this->memberOf('MIS');
        $this->clearWarnings($analyst);

        $engineerDay = $this->file($engineer);
        $analystDay = $this->file($analyst);

        $this->assertSame([1, 'Pending'], $this->flagOf($engineer, $engineerDay));
        $this->assertSame(0, $this->flagOf($analyst, $analystDay)[0]);
        $this->assertSame(PortalReportReview::PENDING, $this->reviewStatusOf($engineer, $engineerDay.'-daily'));
        $this->assertSame(PortalReportReview::APPROVED, $this->reviewStatusOf($analyst, $analystDay.'-daily'));
    }

    // The reported ask (2026-10-13): Steven heads Engineering, so his own daily report is approved when filed.
    public function test_the_engineering_head_s_own_daily_report_is_approved_at_once(): void
    {
        $head = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'management'")
            ->whereRaw('LOWER(TRIM(department)) = ?', ['engineering'])
            ->whereExists(fn ($query) => $query->from('employees_projects')
                ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
                ->whereColumn('employees_projects.employee_id', 'employees.id')
                ->whereNull('projects.deleted_at')
                ->tap(PortalProjectApproval::openForWork(...)))
            ->first();
        $this->assertNotNull($head, 'No active Engineering head with a project.');
        $this->clearWarnings($head);

        $day = $this->file($head);

        $this->assertSame(0, $this->flagOf($head, $day)[0]);
        $this->assertSame(PortalReportReview::APPROVED, $this->reviewStatusOf($head, $day.'-daily'));
        $this->assertTrue(PortalRole::isDepartmentHead('Admin', ' Management '));
        $this->assertFalse(PortalRole::isDepartmentHead('User', 'Management'));
        $this->assertFalse(PortalRole::isDepartmentHead('Admin', 'Staff'));
    }

    // The reported ask (2026-10-13): an MIS member on a warning waits like anyone flagged.
    public function test_a_flagged_mis_daily_report_waits_for_review(): void
    {
        $analyst = $this->memberOf('MIS');
        $this->insertWarning($analyst);

        $day = $this->file($analyst);

        $this->assertSame([1, 'Pending'], $this->flagOf($analyst, $day));
        $this->assertSame(PortalReportReview::PENDING, $this->reviewStatusOf($analyst, $day.'-daily'));
    }

    // The reported ask: User Requests' Daily Reports tab shows a member their own reviewed reports and the answers.
    public function test_a_member_follows_their_own_daily_report_through_review(): void
    {
        $engineer = $this->memberOf('Engineering');
        $other = $this->memberOf('MIS');
        $otherHeaders = $this->headers($other);
        // What the MIS member reads before the filing: real reports of theirs may share the day.
        $theirsBefore = $this->withHeaders($otherHeaders)->getJson(self::REVIEWS)->assertOk()->json('data');
        $day = $this->file($engineer);
        $headers = $this->headers($engineer);

        $listed = $this->ownReview($engineer, $day);
        $this->assertSame('daily', $listed['kind']);
        $this->assertNull($listed['memberName']);
        $this->assertSame('pending', $listed['status']);
        $this->assertNotEmpty($listed['entries']);

        // An admin of Engineering turns it down.
        $this->lines($engineer, $day)->update(['flag_status' => 'Rejected', 'flag_remarks' => 'Hours do not match the tracker']);
        Cache::flush();
        $rejected = $this->ownReview($engineer, $day);
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('Hours do not match the tracker', $rejected['approverRemarks']);
        $this->assertSame(PortalReportReview::REJECTED, $this->reviewStatusOf($engineer, $day.'-daily'));

        // Fixed and saved again, it goes back to the reviewer.
        $entries = $this->reportEntries($engineer, 6.0);
        $this->withHeaders($headers)->patchJson(self::SUBMITTED.'/'.$day.'-daily', [
            'entries' => [[...$entries[0], 'elementChange' => 0]],
        ])->assertOk();
        $this->assertSame([1, 'Pending'], $this->flagOf($engineer, $day));
        $this->assertSame('pending', $this->ownReview($engineer, $day)['status']);

        // Nobody else reads it: the MIS member's list is what it was before the filing.
        $theirs = $this->withHeaders($otherHeaders)->getJson(self::REVIEWS)->assertOk()->json('data');
        $this->assertSame(array_column($theirsBefore, 'id'), array_column($theirs, 'id'));
    }

    // A late report keeps the older rule, a warning, beside its own late approval.
    public function test_a_late_report_waits_on_its_late_approval_not_on_the_department(): void
    {
        $engineer = $this->memberOf('Engineering');
        $this->clearWarnings($engineer);
        $day = $this->freeLateDay($engineer);

        $this->withHeaders($this->headers($engineer))->postJson(self::SUBMITTED, [
            'kind' => 'late',
            'reports' => [['reportDate' => $day, 'remarks' => 'Missed the day', 'entries' => $this->reportEntries($engineer)]],
        ])->assertCreated();

        $this->assertSame(0, $this->flagOf($engineer, $day)[0]);
        $this->assertSame(PortalReportReview::PENDING, $this->reviewStatusOf($engineer, $day.'-late'));
        $this->assertNotContains($day, array_column(
            $this->withHeaders($this->headers($engineer))->getJson(self::REVIEWS)->assertOk()->json('data'),
            'submittedOn',
        ));
    }

    // A late report stands at the worse of its review and its late approval; one that never waited is approved.
    public function test_where_a_report_stands(): void
    {
        $this->assertSame('approved', PortalReportReview::status('daily', false, 'Pending'));
        $this->assertSame('pending', PortalReportReview::status('daily', true, null));
        $this->assertSame('rejected', PortalReportReview::status('daily', true, 'Rejected'));
        $this->assertSame('approved', PortalReportReview::status('late', false, null, 'Approved'));
        $this->assertSame('pending', PortalReportReview::status('late', true, 'Approved', null));
        $this->assertSame('rejected', PortalReportReview::status('late', true, 'Pending', 'Rejected'));

        $this->assertTrue(PortalReportReview::waits('daily', ' engineering ', false));
        // Engineering's own head is approved at once, unless a warning holds them like anyone.
        $this->assertFalse(PortalReportReview::waits('daily', 'Engineering', false, true));
        $this->assertTrue(PortalReportReview::waits('daily', 'Engineering', true, true));
        $this->assertTrue(PortalReportReview::waits('daily', 'MIS', true));
        $this->assertFalse(PortalReportReview::waits('daily', 'MIS', false));
        $this->assertTrue(PortalReportReview::waits('daily', 'Operations', true));
        $this->assertFalse(PortalReportReview::waits('late', 'Engineering', false));
    }

    // An active member of the department who is on at least one project open for work.
    private function memberOf(string $department): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw('LOWER(TRIM(department)) = ?', [strtolower($department)])
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereExists(fn ($query) => $query->from('employees_projects')
                ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
                ->whereColumn('employees_projects.employee_id', 'employees.id')
                ->whereNull('projects.deleted_at')
                ->tap(PortalProjectApproval::openForWork(...)))
            ->first();
        $this->assertNotNull($employee, "No active {$department} member with a project.");

        return $employee;
    }

    // An older weekday with no report and no leave on it.
    private function freeLateDay(Employee $employee): string
    {
        $name = PortalSubmittedReportPresenter::memberName($employee->first_name, $employee->last_name);
        for ($daysAgo = 40; $daysAgo < 400; $daysAgo++) {
            $day = $this->freeReportDate($employee, $daysAgo);
            if (! DB::connection('portal')->table('requests')->where('name', $name)->where('request_date', $day)->exists()) {
                return $day;
            }
        }

        $this->fail('No free late day was available.');
    }

    private function file(Employee $employee): string
    {
        $day = $this->freeReportDate($employee);
        $this->withHeaders($this->headers($employee))->postJson(self::SUBMITTED, $this->dailyReportBody($employee, $day))->assertCreated();

        return $day;
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private function flagOf(Employee $employee, string $day): array
    {
        $row = $this->lines($employee, $day)->first(['employees_user_reports.is_flag', 'employees_user_reports.flag_status']);
        $this->assertNotNull($row);

        return [(int) $row->is_flag, $row->flag_status];
    }

    private function lines(Employee $employee, string $day): Builder
    {
        return DB::connection('portal')->table('employees_user_reports')
            ->join('user_reports', 'user_reports.id', '=', 'employees_user_reports.user_report_id')
            ->where('employees_user_reports.employee_id', $employee->getKey())
            ->where('user_reports.report_date', $day);
    }

    private function reviewStatusOf(Employee $employee, string $reportId): ?string
    {
        $reports = collect($this->withHeaders($this->headers($employee))->getJson(self::SUBMITTED)->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($reports->has($reportId), "Report {$reportId} is not on the list.");

        return $reports[$reportId]['reviewStatus'];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownReview(Employee $employee, string $day): array
    {
        $rows = collect($this->withHeaders($this->headers($employee))->getJson(self::REVIEWS)->assertOk()->json('data'))
            ->where('submittedOn', $day);
        $this->assertCount(1, $rows);

        return $rows->first();
    }

    /**
     * @return array<string, string>
     */
    private function headers(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function insertWarning(Employee $employee): void
    {
        $warningId = (int) DB::connection('portal')->table('warnings')->insertGetId([
            'warning_date' => Carbon::today()->toDateString(),
            'description' => 'Test warning',
            'status' => 'Active',
            'violation_type' => 'N/A',
        ]);
        DB::connection('portal')->table('employees_warnings')->insert(['employee_id' => $employee->getKey(), 'warning_id' => $warningId]);
    }

    private function clearWarnings(Employee $employee): void
    {
        DB::connection('portal')->table('employees_warnings')->where('employee_id', $employee->getKey())->delete();
    }
}
