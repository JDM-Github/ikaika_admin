<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreRecycleKey;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalSubmittedReportsTest extends TestCase
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

    public function test_catalog_lists_submitted_reports_under_portal_sections(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('sections.1.name', 'reports')
            ->assertJsonPath('sections.1.resources.0.name', 'submitted')
            ->assertJsonPath('sections.1.resources.0.url', '/api/development/portal/reports/submitted')
            ->assertJsonPath('sections.1.requires_admin', false);
    }

    public function test_a_member_reads_only_their_own_skinny_grouped_history(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();

        $this->insertLine($actor, [
            'report_date' => '2026-08-20',
            'hours_rendered' => 4,
            'change_in_elements' => 2,
            'remarks' => null,
            'late_submission' => null,
        ], $project, $activity, $earn);
        $this->insertLine($actor, [
            'report_date' => '2026-08-20',
            'hours_rendered' => 3.5,
            'change_in_elements' => 1,
            'remarks' => null,
            'late_submission' => null,
        ], $project, $activity, $earn);
        $this->insertLine($actor, [
            'report_date' => '2026-08-19',
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'remarks' => 'Power outage',
            'late_submission' => 'Yes',
        ], $project, $activity, $earn);
        $this->insertLine($other, [
            'report_date' => '2026-08-20',
            'hours_rendered' => 8,
            'change_in_elements' => 99,
            'remarks' => 'OTHER-EMPLOYEE-SECRET',
            'late_submission' => null,
        ], $project, $activity, $earn);

        $token = $this->loginToken($actor);
        $headers = ['Authorization' => "Bearer {$token}"];

        $response = $this->getJson(
            '/api/development/portal/reports/submitted?from=2026-08-01&to=2026-08-31&employee_id='.$other->id,
            $headers,
        );

        $response->assertOk()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'submitted')
            ->assertJsonPath('range.from', '2026-08-01')
            ->assertJsonPath('range.to', '2026-08-31');

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $daily = collect($data)->firstWhere('id', '2026-08-20-daily');
        $late = collect($data)->firstWhere('id', '2026-08-19-late');
        $this->assertIsArray($daily);
        $this->assertIsArray($late);
        $this->assertSame('daily', $daily['kind']);
        $this->assertCount(2, $daily['entries']);
        $this->assertSame(7.5, $daily['entries'][0]['hoursRendered'] + $daily['entries'][1]['hoursRendered']);
        $this->assertSame('late', $late['kind']);
        $this->assertSame('Power outage', $late['reason']);
        $this->assertSame($actor->id_no, $daily['referenceCode']);
        $this->assertSame(
            PortalSubmittedReportPresenter::memberName($actor->first_name, $actor->last_name),
            $daily['memberName'],
        );

        $first = $daily['entries'][0];
        $this->assertSame(
            ['id', 'projectLabel', 'activityLabel', 'earnCodeLabel', 'hoursRendered', 'elementChange'],
            array_keys($first),
        );
        $this->assertNotSame('Unassigned', $first['projectLabel']);
        $this->assertArrayNotHasKey('approval', $daily);
        $this->assertArrayNotHasKey('late_submission_approval', $daily);
        $this->assertArrayNotHasKey('approver_remarks', $daily);
        $this->assertArrayNotHasKey('bank_account_number', $daily);
        $this->assertSame(2, $response->json('counts.reports'));
        $this->assertSame(1, $response->json('counts.daily'));
        $this->assertSame(1, $response->json('counts.late'));
        $this->assertSame(15.5, $response->json('counts.hours'));
        $this->assertIsArray($response->json('leaveDays'));
        $this->assertIsArray($response->json('offsetDays'));

        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $encoded);
        $this->assertStringNotContainsString('bank_account', $encoded);
    }

    public function test_lookups_are_batched_not_per_row(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        for ($day = 10; $day <= 14; $day++) {
            $this->insertLine($actor, [
                'report_date' => sprintf('2026-08-%02d', $day),
                'hours_rendered' => 8,
            ], $project, $activity, $earn);
        }

        $token = $this->loginToken($actor);
        $queries = [];
        DB::connection('portal')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson(
            '/api/development/portal/reports/submitted?from=2026-08-01&to=2026-08-31',
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()->assertJsonPath('counts.reports', 5);

        $this->assertLessThanOrEqual(9, count($queries));
        $this->assertSame(1, $this->countContaining($queries, 'offset_work_day'));
        $this->assertSame(1, $this->countContaining($queries, 'projects_user_reports'));
        $this->assertSame(1, $this->countContaining($queries, 'user_reports_activity_codes'));
        $this->assertSame(1, $this->countContaining($queries, 'user_reports_earn_codes'));
    }

    public function test_members_are_allowed_and_guests_are_not(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);

        $this->getJson('/api/development/portal/reports/submitted')
            ->assertUnauthorized();

        $this->getJson('/api/development/portal/reports/submitted', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk();
    }

    public function test_invalid_dates_are_rejected_and_wide_ranges_are_clamped(): void
    {
        $actor = $this->activeMember();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->getJson('/api/development/portal/reports/submitted?from=yesterday', $headers)
            ->assertStatus(422);

        $this->getJson('/api/development/portal/reports/submitted?from=2026-08-31&to=2026-08-01', $headers)
            ->assertStatus(422);

        $clamped = $this->getJson(
            '/api/development/portal/reports/submitted?from=2010-01-01&to=2026-08-31',
            $headers,
        )->assertOk();

        $this->assertSame('2024-08-01', $clamped->json('range.from'));
        $this->assertSame('2026-08-31', $clamped->json('range.to'));
    }

    public function test_leave_and_offset_occupy_the_calendar_including_pending_and_weekends(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertRequest($actor, [
            'request_date' => '2026-08-13',
            'status' => 'Pending',
        ]);
        $this->insertRequest($actor, [
            'request_date' => '2026-08-14',
            'status' => 'Rejected',
        ]);
        $this->insertRequest($actor, [
            'request_date' => '2026-08-11',
            'status' => 'Cancelled',
        ]);
        $this->insertRequest($actor, [
            'request_date' => '2026-08-12',
            'no_of_hours' => 4,
            'status' => 'Approved',
            'type' => 'Overtime',
        ]);
        $this->insertRequest($actor, [
            'request_date' => '2026-08-09',
            'no_of_hours' => 8,
            'original_work_day' => '2026-08-08',
            'offset_work_day' => '2026-08-10',
            'status' => 'Approved',
            'type' => 'Offset',
        ]);
        $this->insertRequest($other, [
            'request_date' => '2026-08-18',
            'status' => 'Pending',
        ]);

        $response = $this->getJson(
            '/api/development/portal/reports/submitted?from=2026-08-01&to=2026-08-31',
            $headers,
        )->assertOk();

        $leave = $response->json('leaveDays');
        $offset = $response->json('offsetDays');
        $this->assertIsArray($leave);
        $this->assertIsArray($offset);
        $this->assertContains('2026-08-13', $leave);
        $this->assertContains('2026-08-14', $leave);
        $this->assertNotContains('2026-08-11', $leave);
        $this->assertNotContains('2026-08-12', $leave);
        $this->assertContains('2026-08-08', $offset);
        $this->assertContains('2026-08-10', $offset);
        $this->assertNotContains('2026-08-18', $leave);
        $this->assertSame($leave, array_values(array_unique($leave)));
        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('test occupancy', $encoded);
    }

    public function test_a_member_replaces_their_own_report_inside_the_seven_day_window(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 4,
            'change_in_elements' => 1,
        ], $project, $activity, $earn);
        $this->insertLine($other, [
            'report_date' => $today,
            'hours_rendered' => 8,
            'change_in_elements' => 99,
            'remarks' => 'OTHER-EMPLOYEE-SECRET',
        ], $project, $activity, $earn);

        $projectLabel = PortalSubmittedReportPresenter::projectLabel(
            $project->project_number ?? null,
            $project->project_name ?? null,
        );
        $activityLabel = PortalSubmittedReportPresenter::activityLabel(
            $activity->name ?? null,
            $activity->id_no ?? null,
        );

        $id = $today.'-daily';
        $response = $this->patchJson(
            '/api/development/portal/reports/submitted/'.$id.'?employee_id='.$other->id,
            [
                'entries' => [[
                    'projectLabel' => $projectLabel,
                    'activityLabel' => $activityLabel,
                    'hoursRendered' => 6.5,
                    'elementChange' => 3,
                ]],
                'remarks' => 'Adjusted hours',
            ],
            $headers,
        );

        $response->assertOk()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'submitted')
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.kind', 'daily')
            ->assertJsonPath('data.submittedOn', $today)
            ->assertJsonPath('data.reason', 'Adjusted hours');
        $this->assertSame(6.5, $response->json('data.entries.0.hoursRendered'));
        $this->assertEquals(3, $response->json('data.entries.0.elementChange'));

        $listed = $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $headers,
        )->assertOk();
        $listedDaily = collect($listed->json('data'))->firstWhere('id', $id);
        $this->assertIsArray($listedDaily);
        $this->assertSame(6.5, $listedDaily['entries'][0]['hoursRendered']);
        $this->assertSame('Adjusted hours', $listedDaily['reason']);

        $otherHeaders = ['Authorization' => 'Bearer '.$this->loginToken($other)];
        $otherList = $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $otherHeaders,
        )->assertOk();
        $otherDaily = collect($otherList->json('data'))->firstWhere('id', $id);
        $this->assertIsArray($otherDaily);
        $this->assertEquals(8, $otherDaily['entries'][0]['hoursRendered']);
        $encoded = $response->getContent();
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $encoded);

        $edited = Action::query()
            ->where('product', 'portal')
            ->where('recycle_key', CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily'))
            ->where('action_type', CoreActionType::EDIT)
            ->first();
        $this->assertNotNull($edited);
        $this->assertSame('edit', $edited->parameters['action_type'] ?? null);
        $this->assertSame('Adjusted hours', $edited->parameters['parameters']['remarks'] ?? null);
    }

    public function test_a_member_deletes_their_own_report_inside_the_seven_day_window(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);

        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$today.'-daily',
            [],
            $headers,
        )->assertNoContent();

        $listed = $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $headers,
        )->assertOk();
        $ids = collect($listed->json('data'))->pluck('id')->all();
        $this->assertNotContains($today.'-daily', $ids);

        $recycleKey = CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily');
        $recycled = Recycle::query()
            ->where('product', 'portal')
            ->where('recycle_key', $recycleKey)
            ->first();
        $this->assertNotNull($recycled);
        $this->assertSame('portal.user_reports', $recycled->database_target);
        $this->assertSame($today.'-daily', $recycled->record_id);
        $this->assertIsArray($recycled->payload);
        $this->assertSame($today.'-daily', $recycled->payload['id'] ?? null);
        $this->assertSame('Daily Report', $recycled->payload['title'] ?? null);
        $this->assertNotEmpty($recycled->payload['lines'] ?? []);

        $deleted = Action::query()
            ->where('product', 'portal')
            ->where('recycle_key', $recycleKey)
            ->where('action_type', CoreActionType::DELETE)
            ->first();
        $this->assertNotNull($deleted);
        $this->assertSame('delete', $deleted->parameters['action_type'] ?? null);
        $this->assertSame('portal', $deleted->parameters['product'] ?? null);
    }

    public function test_writes_outside_the_window_and_foreign_groups_are_refused(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $stale = Carbon::today()->subDays(8)->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($actor, [
            'report_date' => $stale,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);
        $this->insertLine($other, [
            'report_date' => Carbon::today()->toDateString(),
            'hours_rendered' => 8,
        ], $project, $activity, $earn);

        $this->patchJson(
            '/api/development/portal/reports/submitted/'.$stale.'-daily',
            ['entries' => [['projectLabel' => 'x', 'activityLabel' => 'y', 'hoursRendered' => 1]]],
            $headers,
        )->assertStatus(422);

        $this->deleteJson(
            '/api/development/portal/reports/submitted/'.$stale.'-daily',
            [],
            $headers,
        )->assertStatus(422);

        $this->patchJson(
            '/api/development/portal/reports/submitted/'.Carbon::today()->toDateString().'-daily',
            ['entries' => [['projectLabel' => 'x', 'activityLabel' => 'y', 'hoursRendered' => 1]]],
            $headers,
        )->assertNotFound();

        $this->deleteJson(
            '/api/development/portal/reports/submitted/not-a-group',
            [],
            $headers,
        )->assertNotFound();

        $this->patchJson(
            '/api/development/portal/reports/submitted/'.Carbon::today()->toDateString().'-daily',
            ['entries' => [['projectLabel' => 'x', 'activityLabel' => 'y', 'hoursRendered' => 1]]],
        )->assertUnauthorized();
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

    private function loginToken(Employee $employee): string
    {
        $this->assertNotEmpty($employee->id_no);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRequest(Employee $employee, array $attributes): int
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($employee->first_name) ? $employee->first_name : null,
            is_string($employee->last_name) ? $employee->last_name : null,
        );

        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $attributes['request_date'],
            'name' => $name,
            'no_of_hours' => $attributes['no_of_hours'] ?? null,
            'original_work_day' => $attributes['original_work_day'] ?? null,
            'offset_work_day' => $attributes['offset_work_day'] ?? null,
            'reason' => $attributes['reason'] ?? 'test occupancy',
            'status' => $attributes['status'] ?? 'Pending',
            'type' => $attributes['type'] ?? null,
        ]);
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
     * @param  list<string>  $queries
     */
    private function countContaining(array $queries, string $needle): int
    {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, $needle)));
    }
}
