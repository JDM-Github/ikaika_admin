<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use App\Support\Portal\PortalNotificationType;
use App\Support\Portal\PortalSubmittedReportPresenter;
use App\Support\Portal\PortalTimezone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Late reports wait on an approver: filed with late_submission_approval Pending, listed on the
 * Manage Requests queue one row per member and day, decided there, and read back by their filer.
 */
class PortalLateReportsTest extends TestCase
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

    public function test_a_late_filing_waits_on_an_approver_and_tells_the_approvers(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);

        $this->fileLate($actor, $day, 2)->assertCreated();

        $lines = $this->linesOf($actor, $day);
        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame('Yes', $line->late_submission);
            $this->assertSame('Pending', $line->late_submission_approval);
        }

        $notice = PortalNotification::query()
            ->where('employee_id', $this->adminEmployee()->getKey())
            ->where('type', PortalNotificationType::REPORT_FILED_LATE)
            ->latest('id')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('/manage/manage-requests', $notice->link_path);
        $this->assertSame($actor->getKey().':'.$day, $notice->payload['recordId'] ?? null);
    }

    public function test_a_daily_filing_asks_no_one(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 0, 7);

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [['reportDate' => $day, 'entries' => [$this->entry($actor, 0)]]],
        ], $this->headersFor($actor))->assertCreated();

        $this->assertNull($this->linesOf($actor, $day)[0]->late_submission_approval);
    }

    public function test_the_queue_lists_one_row_per_member_and_day(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->fileLate($actor, $day, 2, 'Filed after a power outage')->assertCreated();

        $row = $this->queueRow($actor->getKey().':'.$day);

        $this->assertSame($day, $row['submittedOn']);
        $this->assertSame($this->today()->toDateString(), $row['createdOn']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('Filed after a power outage', $row['reason']);
        $this->assertCount(2, $row['entries']);
        $this->assertSame(PortalSubmittedReportPresenter::memberName($actor->first_name, $actor->last_name), $row['memberName']);
    }

    public function test_a_daily_report_stays_off_the_late_queue(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->insertLine($actor, $day, null, null);

        $ids = collect($this->queue()->json('lateReports'))->pluck('id')->all();

        $this->assertNotContains($actor->getKey().':'.$day, $ids);
    }

    // Airtable wrote YES and left the decision on older rows it never asked about: both still list.
    public function test_the_queue_reads_airtable_late_rows_too(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 60);
        $this->insertLine($actor, $day, 'YES', null);

        $this->assertSame('pending', $this->queueRow($actor->getKey().':'.$day)['status']);
    }

    public function test_approving_decides_every_line_and_tells_the_member(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->fileLate($actor, $day, 2)->assertCreated();
        $id = $actor->getKey().':'.$day;

        $this->decide([['id' => $id, 'kind' => 'late', 'status' => 'approved', 'approverRemarks' => 'Outage confirmed.']])
            ->assertOk()
            ->assertJsonPath('saved', 1);

        foreach ($this->linesOf($actor, $day) as $line) {
            $this->assertSame('Approved', $line->late_submission_approval);
            $this->assertSame('Outage confirmed.', $line->approver_remarks);
        }
        $row = $this->queueRow($id);
        $this->assertSame('approved', $row['status']);
        $this->assertSame('Outage confirmed.', $row['approverRemarks']);

        $notice = PortalNotification::query()
            ->where('employee_id', $actor->getKey())
            ->where('type', 'reports.submitted.approved')
            ->latest('id')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('Late report approved', $notice->title);
    }

    public function test_a_late_report_can_be_refused_and_set_back_to_pending(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->fileLate($actor, $day)->assertCreated();
        $id = $actor->getKey().':'.$day;

        $this->decide([['id' => $id, 'kind' => 'late', 'status' => 'rejected']])->assertOk();
        $this->assertSame('Rejected', $this->linesOf($actor, $day)[0]->late_submission_approval);

        $this->decide([['id' => $id, 'kind' => 'late', 'status' => 'pending']])->assertOk();
        $this->assertSame('Pending', $this->linesOf($actor, $day)[0]->late_submission_approval);
    }

    public function test_the_member_reads_their_own_late_reports_and_the_decision(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $day = $this->freeDate($actor, 40);
        $theirs = $this->freeDate($other, 40);
        $this->fileLate($actor, $day)->assertCreated();
        $this->insertLine($other, $theirs, 'Yes', 'Pending');
        $this->decide([['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'rejected', 'approverRemarks' => 'No outage that day.']])
            ->assertOk();

        $own = $this->getJson('/api/development/portal/reports/late', $this->headersFor($actor))
            ->assertOk()
            ->assertJsonPath('resource', 'late');

        $rows = collect($own->json('data'));
        $this->assertSame([$actor->getKey().':'.$day], $rows->pluck('id')->all());
        $this->assertSame('rejected', $rows[0]['status']);
        $this->assertSame('No outage that day.', $rows[0]['approverRemarks']);
        // The filer's own list, so no name: the same convention every own list follows.
        $this->assertNull($rows[0]['memberName']);
    }

    public function test_editing_a_late_report_sends_it_back_for_approval(): void
    {
        $actor = $this->activeMember();
        $day = $this->recentFreeDate($actor);
        $this->fileLate($actor, $day)->assertCreated();
        $this->decide([['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'approved', 'approverRemarks' => 'Fine.']])
            ->assertOk();

        $this->patchJson('/api/development/portal/reports/submitted/'.$day.'-late', [
            'entries' => [array_merge($this->entry($actor, 0), ['hoursRendered' => 3])],
            'remarks' => 'Corrected the hours',
        ], $this->headersFor($actor))->assertOk();

        $line = $this->linesOf($actor, $day)[0];
        $this->assertSame('Pending', $line->late_submission_approval);
        // The earlier answer stays readable beside the report it no longer covers.
        $this->assertSame('Fine.', $line->approver_remarks);
    }

    public function test_a_restored_late_report_keeps_its_decision(): void
    {
        $actor = $this->activeMember();
        $day = $this->recentFreeDate($actor);
        $headers = $this->headersFor($actor);
        $this->fileLate($actor, $day)->assertCreated();
        $this->decide([['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'approved', 'approverRemarks' => 'Fine.']])
            ->assertOk();

        $this->deleteJson('/api/development/portal/reports/submitted/'.$day.'-late', [], $headers)->assertNoContent();
        $recycled = Recycle::query()
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('recycle_key', CoreRecycleKey::submittedReport((int) $actor->getKey(), $day, 'late'))
            ->first();
        $this->assertNotNull($recycled);
        $this->postJson('/api/development/portal/administration/recycle-bin/'.$recycled->id.'/restore', [], $headers)
            ->assertNoContent();

        $line = $this->linesOf($actor, $day)[0];
        $this->assertSame('Approved', $line->late_submission_approval);
        $this->assertSame('Fine.', $line->approver_remarks);
    }

    public function test_a_late_decision_names_a_member_and_day_that_hold_a_late_report(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->insertLine($actor, $day, null, null);

        $this->decide([['id' => '123', 'kind' => 'late', 'status' => 'approved']])->assertStatus(422);
        // A daily report on that day is not a late report to decide.
        $this->decide([['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'approved']])->assertNotFound();
    }

    public function test_a_refused_change_rolls_back_a_late_decision_in_the_same_save(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->fileLate($actor, $day)->assertCreated();

        $this->decide([
            ['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'approved'],
            ['id' => '999999999', 'kind' => 'leave', 'status' => 'approved'],
        ])->assertNotFound();

        $this->assertSame('Pending', $this->linesOf($actor, $day)[0]->late_submission_approval);
    }

    public function test_only_an_admin_decides_a_late_report(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor, 40);
        $this->fileLate($actor, $day)->assertCreated();

        $this->patchJson('/api/development/portal/manage/requests', [
            'changes' => [['id' => $actor->getKey().':'.$day, 'kind' => 'late', 'status' => 'approved']],
        ], $this->headersFor($actor))->assertForbidden();

        $this->assertSame('Pending', $this->linesOf($actor, $day)[0]->late_submission_approval);
    }

    private function fileLate(Employee $actor, string $day, int $entries = 1, ?string $remarks = null): TestResponse
    {
        $list = [];
        for ($index = 0; $index < $entries; $index++) {
            $list[] = $this->entry($actor, $index);
        }

        return $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'late',
            'reports' => [['reportDate' => $day, 'remarks' => $remarks, 'entries' => $list]],
        ], $this->headersFor($actor));
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Employee $actor, int $index): array
    {
        $project = DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $actor->getKey())
            ->whereNotNull('projects.project_name')
            ->select(['projects.project_number', 'projects.project_name'])
            ->first();
        $this->assertNotNull($project);
        $activity = DB::connection('portal')->table('activity_codes')->orderBy('id')->skip($index)->first();
        $this->assertNotNull($activity);

        return [
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel($project->project_number ?? null, $project->project_name ?? null),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel($activity->name ?? null, $activity->id_no ?? null),
            'hoursRendered' => 4,
            'elementChange' => 0,
        ];
    }

    private function insertLine(Employee $employee, string $day, ?string $lateSubmission, ?string $lateApproval): int
    {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $day,
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'late_submission' => $lateSubmission,
            'late_submission_approval' => $lateApproval,
            'approval' => 'Approved',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $id,
        ]);

        return $id;
    }

    /**
     * @return list<object>
     */
    private function linesOf(Employee $employee, string $day): array
    {
        return DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employee->getKey())
            ->where('user_reports.report_date', $day)
            ->orderBy('user_reports.id')
            ->select('user_reports.*')
            ->get()
            ->all();
    }

    private function queue(): TestResponse
    {
        return $this->getJson('/api/development/portal/manage/requests', $this->adminHeaders())->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function queueRow(string $id): array
    {
        $row = collect($this->queue()->json('lateReports'))->firstWhere('id', $id);
        $this->assertIsArray($row, 'The late report '.$id.' is not on the queue.');

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function decide(array $changes): TestResponse
    {
        return $this->patchJson('/api/development/portal/manage/requests', ['changes' => $changes], $this->adminHeaders());
    }

    /**
     * A weekday this member has neither reported nor taken leave on. The seeded database carries
     * real timesheets, so a hard-coded offset is not reliably free, and no report is ever filed
     * for a Saturday or a Sunday.
     */
    private function freeDate(Employee $actor, int $daysAgo, int $span = 400): string
    {
        $name = PortalSubmittedReportPresenter::memberName($actor->first_name, $actor->last_name);
        for ($offset = $daysAgo; $offset < $daysAgo + $span; $offset++) {
            $day = $this->today()->subDays($offset);
            if ($day->isWeekend()) {
                continue;
            }
            $candidate = $day->toDateString();
            $reported = DB::connection('portal')->table('user_reports')
                ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
                ->where('employees_user_reports.employee_id', $actor->getKey())
                ->where('user_reports.report_date', $candidate)
                ->exists();
            $onLeave = DB::connection('portal')->table('requests')
                ->where('name', $name)
                ->where('request_date', $candidate)
                ->exists();
            if (! $reported && ! $onLeave) {
                return $candidate;
            }
        }

        $this->fail('No free report date was available for this member.');
    }

    // Inside the seven days a report can still be edited or deleted, and before today, as late is.
    private function recentFreeDate(Employee $actor): string
    {
        return $this->freeDate($actor, 1, 7);
    }

    // The portal's own day, which is the one filing checks against -- not the server's UTC one.
    private function today(): Carbon
    {
        return app(PortalTimezone::class)->today();
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('employees_projects')
                    ->whereColumn('employees_projects.employee_id', 'employees.id');
            })
            ->orderBy('id')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->where('id', '!=', $actor->getKey())
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function adminEmployee(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return $this->headersFor($this->adminEmployee());
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
