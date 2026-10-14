<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalNotificationEmails;
use App\Support\Portal\PortalProjectApproval;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FilesPortalReports;
use Tests\Concerns\NamesDepartmentHeads;
use Tests\TestCase;

/**
 * Report Submission Pages (2026-10-13, user): a daily or late report emails only the head of the
 * filer's department, its Management-level admin; approvers keep their inbox notice.
 */
class PortalReportSubmissionEmailsTest extends TestCase
{
    use DatabaseTransactions;
    use FilesPortalReports;
    use NamesDepartmentHeads;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SUBMITTED = '/api/development/portal/reports/submitted';

    private Employee $member;

    private Employee $head;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->turnNotificationEmailsOn();
        $this->member = $this->memberWithProject();
        [$this->head, $this->admin] = $this->nameDepartmentHead($this->member);
    }

    public function test_a_daily_report_emails_only_the_department_head(): void
    {
        $date = $this->freeReportDate($this->member);
        $this->withToken($this->token($this->member))->postJson(self::SUBMITTED, $this->dailyReportBody($this->member, $date))->assertCreated();

        $this->assertEmailedOnlyTheHead($this->head, $this->admin, 'New daily report');
    }

    public function test_a_late_report_emails_the_head_while_approvers_keep_their_inbox_notice(): void
    {
        $date = $this->freeReportDate($this->member, 40);
        $this->withToken($this->token($this->member))->postJson(self::SUBMITTED, [
            'kind' => 'late',
            'reports' => [['reportDate' => $date, 'entries' => $this->reportEntries($this->member)]],
        ])->assertCreated();

        $this->assertEmailedOnlyTheHead($this->head, $this->admin, 'New late report');
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $this->admin->getKey())
            ->where('type', 'reports.late.filed')
            ->exists());
    }

    public function test_no_email_leaves_without_a_head_or_with_notification_emails_off(): void
    {
        // A department with no head emails nobody.
        $this->seatDepartmentHead((string) $this->member->department, null);
        $this->withToken($this->token($this->member))
            ->postJson(self::SUBMITTED, $this->dailyReportBody($this->member, $this->freeReportDate($this->member)))
            ->assertCreated();
        Mail::assertNothingSent();
        $this->assertSame([], PortalNotificationEmails::departmentHeads($this->member));

        // Nor does someone with no department, and the head never mails themselves.
        $this->assertSame([], PortalNotificationEmails::departmentHeads($this->member->replicate()->forceFill(['department' => ''])));
        $this->seatDepartmentHead((string) $this->member->department, $this->head);
        $this->assertSame([], PortalNotificationEmails::departmentHeads($this->head));

        config(['products.catalog.portal.notification_emails' => false]);
        $this->withToken($this->token($this->member))
            ->postJson(self::SUBMITTED, $this->dailyReportBody($this->member, $this->freeReportDate($this->member)))
            ->assertCreated();
        Mail::assertNothingSent();
    }

    // An active member on at least one project open for work, so a report can be filed.
    private function memberWithProject(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereRaw("TRIM(COALESCE(department, '')) != ''")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereExists(fn ($query) => $query->from('employees_projects')
                ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
                ->whereColumn('employees_projects.employee_id', 'employees.id')
                ->whereNull('projects.deleted_at')
                ->tap(PortalProjectApproval::openForWork(...)))
            ->orderBy('id')
            ->first();
        $this->assertNotNull($employee);
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }
}
