<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreRecycleKey;
use App\Support\Portal\PortalSubmittedReportPresenter;
use App\Support\Portal\PortalTimezone;
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
            ->assertJsonPath('sections.4.name', 'reports')
            ->assertJsonPath('sections.4.resources.0.name', 'submitted')
            ->assertJsonPath('sections.4.resources.0.url', '/api/development/portal/reports/submitted')
            ->assertJsonPath('sections.4.requires_admin', false);
    }

    public function test_a_member_reads_only_their_own_skinny_grouped_history(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();

        $this->insertLine($actor, [
            'report_date' => '2025-02-20',
            'hours_rendered' => 4,
            'change_in_elements' => 2,
            'remarks' => null,
            'late_submission' => null,
        ], $project, $activity, $earn);
        $this->insertLine($actor, [
            'report_date' => '2025-02-20',
            'hours_rendered' => 3.5,
            'change_in_elements' => 1,
            'remarks' => null,
            'late_submission' => null,
        ], $project, $activity, $earn);
        $this->insertLine($actor, [
            'report_date' => '2025-02-19',
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'remarks' => 'Power outage',
            'late_submission' => 'Yes',
        ], $project, $activity, $earn);
        $this->insertLine($other, [
            'report_date' => '2025-02-20',
            'hours_rendered' => 8,
            'change_in_elements' => 99,
            'remarks' => 'OTHER-EMPLOYEE-SECRET',
            'late_submission' => null,
        ], $project, $activity, $earn);

        $token = $this->loginToken($actor);
        $headers = ['Authorization' => "Bearer {$token}"];

        $response = $this->getJson(
            '/api/development/portal/reports/submitted?from=2025-02-01&to=2025-02-28&employee_id='.$other->id,
            $headers,
        );

        $response->assertOk()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'submitted')
            ->assertJsonPath('range.from', '2025-02-01')
            ->assertJsonPath('range.to', '2025-02-28');

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        $daily = collect($data)->firstWhere('id', '2025-02-20-daily');
        $late = collect($data)->firstWhere('id', '2025-02-19-late');
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
                'report_date' => sprintf('2025-02-%02d', $day),
                'hours_rendered' => 8,
            ], $project, $activity, $earn);
        }

        $token = $this->loginToken($actor);
        $queries = [];
        DB::connection('portal')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson(
            '/api/development/portal/reports/submitted?from=2025-02-01&to=2025-02-28',
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

        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'PATCH')
            ->where('resource', 'reports.submitted')
            ->where('record_id', $id)
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame('Adjusted hours', $log->payload['remarks']);
        $this->assertSame(6.5, $log->payload['entries'][0]['hoursRendered']);
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

        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'DELETE')
            ->where('resource', 'reports.submitted')
            ->where('record_id', $today.'-daily')
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame('daily', $log->payload['kind']);
        $this->assertSame($today, $log->payload['submittedOn']);
        $this->assertNotEmpty($log->payload['entries']);
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

    public function test_a_member_files_a_daily_report_and_reads_it_back(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $projectLabel = PortalSubmittedReportPresenter::projectLabel(
            $project->project_number ?? null,
            $project->project_name ?? null,
        );
        $activityLabel = PortalSubmittedReportPresenter::activityLabel(
            $activity->name ?? null,
            $activity->id_no ?? null,
        );

        $response = $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed from the portal',
                'entries' => [[
                    'projectLabel' => $projectLabel,
                    'activityLabel' => $activityLabel,
                    'hoursRendered' => 5.5,
                    'elementChange' => 2,
                ]],
            ]],
        ], $headers);

        $response->assertCreated()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'submitted')
            ->assertJsonPath('data.0.id', $today.'-daily')
            ->assertJsonPath('data.0.kind', 'daily')
            ->assertJsonPath('data.0.reason', 'Filed from the portal');
        $this->assertSame(5.5, $response->json('data.0.entries.0.hoursRendered'));

        $this->getJson(
            '/api/development/portal/reports/submitted?from='.$today.'&to='.$today,
            $headers,
        )->assertOk()->assertJsonPath('data.0.id', $today.'-daily');

        $action = Action::query()
            ->where('recycle_key', CoreRecycleKey::submittedReport((int) $actor->getKey(), $today, 'daily'))
            ->where('action_type', CoreActionType::ADD)
            ->first();
        $this->assertNotNull($action);
        $this->assertSame($today.'-daily', $action->record_id);

        $readable = Carbon::createFromFormat('Y-m-d', $today);
        $this->assertNotFalse($readable);
        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'INSERT')
            ->where('resource', 'reports.submitted')
            ->where('record_id', $today.'-daily')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame(
            '{UserName|You} added a daily report for '.$readable->format('l, F j, Y'),
            $log->message,
        );
        $this->assertIsArray($log->payload);
        $this->assertSame('daily', $log->payload['kind']);
        $this->assertSame($today, $log->payload['submittedOn']);
        $this->assertSame('Filed from the portal', $log->payload['remarks']);
        $this->assertSame($projectLabel, $log->payload['entries'][0]['projectLabel']);
        $this->assertSame($activityLabel, $log->payload['entries'][0]['activityLabel']);
        $this->assertSame(5.5, $log->payload['entries'][0]['hoursRendered']);
    }

    public function test_a_daily_report_is_flagged_when_the_filer_has_an_active_warning(): void
    {
        $actor = $this->activeMember();
        $this->insertWarning($actor, 'Active');
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed while on a warning',
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 8,
                    'elementChange' => 1,
                ]],
            ]],
        ], $headers)->assertCreated();

        $junction = DB::connection('portal')->table('employees_user_reports')
            ->join('user_reports', 'user_reports.id', '=', 'employees_user_reports.user_report_id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $today)
            ->first();
        $this->assertNotNull($junction);
        $this->assertSame(1, (int) $junction->is_flag);
        $this->assertSame('Pending', $junction->flag_status);
    }

    public function test_a_daily_report_is_not_flagged_by_an_inactive_warning(): void
    {
        $actor = $this->activeMember();
        // The seeded roster already carries real Active warnings on some members -- activeMember()
        // itself resolves to one -- so this test cannot assume a clean slate; it makes one.
        DB::connection('portal')->table('employees_warnings')->where('employee_id', $actor->getKey())->delete();
        $this->insertWarning($actor, 'Inactive');
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed with only a lapsed warning on file',
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 8,
                    'elementChange' => 1,
                ]],
            ]],
        ], $headers)->assertCreated();

        $junction = DB::connection('portal')->table('employees_user_reports')
            ->join('user_reports', 'user_reports.id', '=', 'employees_user_reports.user_report_id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $today)
            ->first();
        $this->assertNotNull($junction);
        $this->assertSame(0, (int) $junction->is_flag);
    }

    /*
     * The flag is set once at filing time. Proven here by deactivating the warning between filing
     * and editing: if the edit re-derived the flag instead of carrying it, this report would come
     * back unflagged after the edit, which would be wrong -- the report was filed while the
     * employee was on a warning, and that fact does not change because the warning later lapsed.
     */
    public function test_editing_a_flagged_report_keeps_its_flag_without_recomputing_it(): void
    {
        $actor = $this->activeMember();
        // Deactivating only this warning has to be enough to prove the point below, so the actor
        // must start with none of the seeded roster's own Active warnings still attached.
        DB::connection('portal')->table('employees_warnings')->where('employee_id', $actor->getKey())->delete();
        $warningId = $this->insertWarning($actor, 'Active');
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $projectLabel = PortalSubmittedReportPresenter::projectLabel(
            $project->project_number ?? null,
            $project->project_name ?? null,
        );
        $activityLabel = PortalSubmittedReportPresenter::activityLabel(
            $activity->name ?? null,
            $activity->id_no ?? null,
        );

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed while on a warning',
                'entries' => [[
                    'projectLabel' => $projectLabel,
                    'activityLabel' => $activityLabel,
                    'hoursRendered' => 8,
                    'elementChange' => 1,
                ]],
            ]],
        ], $headers)->assertCreated();

        DB::connection('portal')->table('warnings')->where('id', $warningId)->update(['status' => 'Inactive']);

        $this->patchJson('/api/development/portal/reports/submitted/'.$today.'-daily', [
            'entries' => [[
                'projectLabel' => $projectLabel,
                'activityLabel' => $activityLabel,
                'hoursRendered' => 6,
                'elementChange' => 2,
            ]],
        ], $headers)->assertOk();

        $junction = DB::connection('portal')->table('employees_user_reports')
            ->join('user_reports', 'user_reports.id', '=', 'employees_user_reports.user_report_id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $today)
            ->first();
        $this->assertNotNull($junction);
        $this->assertSame(1, (int) $junction->is_flag);
    }

    // The member's own view of Flag Reports: no name, since it can only ever be their own.
    public function test_get_reports_flagged_returns_only_the_signed_in_member_s_own_flagged_reports(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        DB::connection('portal')->table('employees_warnings')->where('employee_id', $actor->getKey())->delete();
        $this->insertWarning($actor, 'Active');
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed while on a warning',
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 8,
                    'elementChange' => 1,
                ]],
            ]],
        ], $headers)->assertCreated();
        // A different member's unrelated report, flagged or not, must never leak into this read.
        $this->insertLine($other, [
            'report_date' => $today,
            'remarks' => 'OTHER-EMPLOYEE-SECRET',
        ], $project, $activity, $this->firstEarnCode());

        $response = $this->getJson(
            '/api/development/portal/reports/flagged?from='.$today.'&to='.$today,
            $headers,
        )->assertOk()
            ->assertJsonPath('section', 'reports')
            ->assertJsonPath('resource', 'flagged');

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $row = $data[0];
        $this->assertIsArray($row);
        $this->assertNull($row['memberName']);
        $this->assertIsString($row['id']);
        $this->assertSame($today, $row['submittedOn']);
        $this->assertSame('daily', $row['kind']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['approverRemarks']);
        $this->assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $response->getContent());
    }

    // A report filed without an active warning is never flagged, so it never appears here.
    public function test_get_reports_flagged_omits_a_report_that_was_never_flagged(): void
    {
        $actor = $this->activeMember();
        DB::connection('portal')->table('employees_warnings')->where('employee_id', $actor->getKey())->delete();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $today = $this->freeDate($actor, 0);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $today,
                'remarks' => 'Filed with no warning on file',
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 8,
                    'elementChange' => 1,
                ]],
            ]],
        ], $headers)->assertCreated();

        $this->getJson(
            '/api/development/portal/reports/flagged?from='.$today.'&to='.$today,
            $headers,
        )->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_late_report_files_against_an_older_day_and_reads_back_as_late(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $day = $this->freeDate($actor, 40);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'late',
            'reports' => [[
                'reportDate' => $day,
                'remarks' => 'Filed after a power outage',
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 8,
                    'elementChange' => 0,
                ]],
            ]],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.0.id', $day.'-late')
            ->assertJsonPath('data.0.kind', 'late');

        $this->getJson(
            '/api/development/portal/reports/submitted/days?from='.$day.'&to='.$day,
            $headers,
        )->assertOk()
            ->assertJsonPath('data.0.date', $day)
            ->assertJsonPath('data.0.kind', 'late');
    }

    public function test_filing_refuses_a_day_that_already_holds_a_report_of_either_kind(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $today = Carbon::today()->toDateString();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($actor, [
            'report_date' => $today,
            'hours_rendered' => 8,
        ], $project, $activity, $earn);

        $body = [
            'kind' => 'late',
            'reports' => [[
                'reportDate' => $today,
                'entries' => [[
                    'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                        $project->project_number ?? null,
                        $project->project_name ?? null,
                    ),
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 4,
                    'elementChange' => 0,
                ]],
            ]],
        ];

        $this->postJson('/api/development/portal/reports/submitted', $body, $headers)
            ->assertStatus(422);

        $lines = DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $today)
            ->count();
        $this->assertSame(1, $lines);
    }

    public function test_filing_refuses_a_future_day_and_a_daily_report_older_than_the_window(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $entries = [[
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                $project->project_number ?? null,
                $project->project_name ?? null,
            ),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                $activity->name ?? null,
                $activity->id_no ?? null,
            ),
            'hoursRendered' => 4,
            'elementChange' => 0,
        ]];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'late',
            'reports' => [['reportDate' => Carbon::tomorrow()->toDateString(), 'entries' => $entries]],
        ], $headers)->assertStatus(422);

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [['reportDate' => Carbon::today()->subDays(30)->toDateString(), 'entries' => $entries]],
        ], $headers)->assertStatus(422);

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'weekly',
            'reports' => [['reportDate' => Carbon::today()->toDateString(), 'entries' => $entries]],
        ], $headers)->assertStatus(422);
    }

    public function test_filing_names_the_label_the_database_does_not_have(): void
    {
        $actor = $this->activeMember();
        $activity = $this->firstActivity();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [[
                'reportDate' => $this->freeDate($actor, 0),
                'entries' => [[
                    'projectLabel' => 'ZZZZZ No Such Project',
                    'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                        $activity->name ?? null,
                        $activity->id_no ?? null,
                    ),
                    'hoursRendered' => 4,
                    'elementChange' => 0,
                ]],
            ]],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', '"ZZZZZ No Such Project" is not one of your projects.');
    }

    public function test_a_multi_day_filing_is_all_or_nothing(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        $free = $this->freeDate($actor, 2);
        $taken = $this->freeDate($actor, 3);
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $this->insertLine($actor, ['report_date' => $taken, 'hours_rendered' => 8], $project, $activity, $earn);

        $entries = [[
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                $project->project_number ?? null,
                $project->project_name ?? null,
            ),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                $activity->name ?? null,
                $activity->id_no ?? null,
            ),
            'hoursRendered' => 4,
            'elementChange' => 0,
        ]];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [
                ['reportDate' => $free, 'entries' => $entries],
                ['reportDate' => $taken, 'entries' => $entries],
            ],
        ], $headers)->assertStatus(422);

        $this->assertSame(0, DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $free)
            ->count());
    }

    public function test_a_late_report_cannot_be_filed_for_today(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $entries = [[
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                $project->project_number ?? null,
                $project->project_name ?? null,
            ),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                $activity->name ?? null,
                $activity->id_no ?? null,
            ),
            'hoursRendered' => 4,
            'elementChange' => 0,
        ]];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'late',
            'reports' => [[
                'reportDate' => Carbon::today()->toDateString(),
                'entries' => $entries,
            ]],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A late report is for a previous day.');

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'late',
            'reports' => [['reportDate' => $this->freeDate($actor, 1), 'entries' => $entries]],
        ], $headers)->assertCreated();
    }

    public function test_leave_blocks_a_filing_unless_the_request_was_rejected(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $activity = $this->firstActivity();
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($actor)];

        $onLeave = $this->freeDate($actor, 1);
        $refused = $this->freeDate($actor, 2);
        $this->insertRequest($actor, ['request_date' => $onLeave, 'status' => 'Pending']);
        $this->insertRequest($actor, ['request_date' => $refused, 'status' => 'Rejected']);

        $entries = [[
            'projectLabel' => PortalSubmittedReportPresenter::projectLabel(
                $project->project_number ?? null,
                $project->project_name ?? null,
            ),
            'activityLabel' => PortalSubmittedReportPresenter::activityLabel(
                $activity->name ?? null,
                $activity->id_no ?? null,
            ),
            'hoursRendered' => 4,
            'elementChange' => 0,
        ]];

        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [['reportDate' => $onLeave, 'entries' => $entries]],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have leave filed for '.$onLeave.', so there is no report to file.');

        // Rejected leave means the day was worked after all.
        $this->postJson('/api/development/portal/reports/submitted', [
            'kind' => 'daily',
            'reports' => [['reportDate' => $refused, 'entries' => $entries]],
        ], $headers)->assertCreated();

        $days = $this->getJson(
            '/api/development/portal/reports/submitted/days?from='.$refused.'&to='.$onLeave,
            $headers,
        )->assertOk();

        $this->assertSame([$onLeave], $days->json('leaveDays'));
    }

    public function test_the_days_list_reads_two_columns_and_no_label_joins(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstProject();
        $activity = $this->firstActivity();
        $earn = $this->firstEarnCode();
        for ($day = 10; $day <= 14; $day++) {
            $this->insertLine($actor, [
                'report_date' => sprintf('2024-03-%02d', $day),
                'hours_rendered' => 8,
            ], $project, $activity, $earn);
        }

        $token = $this->loginToken($actor);
        $queries = [];
        DB::connection('portal')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson(
            '/api/development/portal/reports/submitted/days?from=2024-03-01&to=2024-03-31',
            ['Authorization' => "Bearer {$token}"],
        )->assertOk();
        // The token check reads the employee row; the payload itself is the two below.
        $queries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => ! str_contains($sql, 'from `employees` '),
        ));

        $this->assertCount(5, $response->json('data'));
        $this->assertSame('2024-03-10', $response->json('data.0.date'));
        $this->assertSame('daily', $response->json('data.0.kind'));
        $this->assertSame(0, $this->countContaining($queries, 'projects_user_reports'));
        $this->assertSame(0, $this->countContaining($queries, 'user_reports_activity_codes'));
        $this->assertSame(0, $this->countContaining($queries, 'user_reports_earn_codes'));
        // The filed days and the leave days, and nothing else.
        $this->assertSame(1, $this->countContaining($queries, 'user_reports'));
        $this->assertSame(1, $this->countContaining($queries, 'offset_work_day'));
        $this->assertLessThanOrEqual(2, count($queries));
    }

    public function test_the_window_ends_on_the_day_the_member_is_standing_in(): void
    {
        $actor = $this->activeMember();
        // 22:00 UTC: already the 2nd in Manila, still the 1st in New York. A server resolving
        // this against its own clock hands one of them a calendar that is a day out. The clock
        // moves before the token is minted, so the session is issued against the same instant.
        Carbon::setTestNow(Carbon::parse('2026-09-01 22:00:00', 'UTC'));
        $token = $this->loginToken($actor);

        $manila = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            PortalTimezone::NAME_HEADER => 'Asia/Manila',
        ])->getJson('/api/development/portal/reports/submitted/days')->assertOk();
        Cache::flush();
        $newYork = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            PortalTimezone::NAME_HEADER => 'America/New_York',
        ])->getJson('/api/development/portal/reports/submitted/days')->assertOk();

        $this->assertSame('2026-09-02', $manila->json('range.to'));
        $this->assertSame('2026-09-01', $newYork->json('range.to'));

        Carbon::setTestNow();
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
    private function insertWarning(Employee $employee, string $status): int
    {
        $warningId = (int) DB::connection('portal')->table('warnings')->insertGetId([
            'warning_date' => Carbon::today()->toDateString(),
            'description' => 'Test warning',
            'status' => $status,
            'violation_type' => 'N/A',
        ]);
        DB::connection('portal')->table('employees_warnings')->insert([
            'employee_id' => $employee->getKey(),
            'warning_id' => $warningId,
        ]);

        return $warningId;
    }

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

    /**
     * A day inside the filing window that this member has not already used. The seeded database
     * carries real timesheets, so a hard-coded offset is not reliably free.
     */
    private function freeDate(Employee $actor, int $daysAgo): string
    {
        for ($offset = $daysAgo; $offset < $daysAgo + 400; $offset++) {
            $candidate = Carbon::today()->subDays($offset)->toDateString();
            $taken = DB::connection('portal')->table('user_reports')
                ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
                ->where('employees_user_reports.employee_id', $actor->getKey())
                ->where('user_reports.report_date', $candidate)
                ->exists();
            if (! $taken) {
                return $candidate;
            }
        }

        $this->fail('No free report date was available for this member.');
    }

    private function firstProject(): object
    {
        $row = DB::connection('portal')->table('projects')->whereNotNull('project_name')->first();
        $this->assertNotNull($row);

        return $row;
    }

    /**
     * Filing is scoped to the member's own board, so a create test cannot use just any project.
     */
    private function firstOwnProject(Employee $actor): object
    {
        $row = DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $actor->getKey())
            ->whereNotNull('projects.project_name')
            ->select(['projects.id', 'projects.project_number', 'projects.project_name', 'projects.type_of_job'])
            ->first();
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
