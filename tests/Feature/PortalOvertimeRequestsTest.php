<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalOvertimeRequestsTest extends TestCase
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

    public function test_overtime_is_filed_against_a_day_the_member_already_reported(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->reportedDate($actor, 2, $project);
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [[
                'requestDate' => $date,
                'reason' => 'Client deadline moved up',
                'entries' => [
                    [
                        'projectLabel' => $this->projectLabel($project),
                        'activityLabel' => 'REVIT MODELING',
                        'hoursRendered' => 2,
                        'elementChange' => 4,
                    ],
                    [
                        'projectLabel' => $this->projectLabel($project),
                        'activityLabel' => 'QC',
                        'hoursRendered' => 1.5,
                        'elementChange' => 0,
                    ],
                ],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('section', 'requests')
            ->assertJsonPath('resource', 'overtime')
            ->assertJsonPath('data.0.requestedFor', $date)
            ->assertJsonPath('data.0.hours', 3.5)
            // Lower-cased the way every request payload sends a status.
            ->assertJsonPath('data.0.status', 'pending');

        $row = DB::connection('portal')->table('requests')
            ->where('id', (int) $response->json('data.0.id'))
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('Overtime', $row->type);
        $this->assertSame($this->memberName($actor), $row->name);
        // The breakdown rides along in the reason: the table holds no line items of its own.
        $this->assertStringContainsString('Client deadline moved up', (string) $row->reason);
        $this->assertStringContainsString('REVIT MODELING', (string) $row->reason);

        $listed = $this->withToken($token)->getJson(
            '/api/development/portal/requests/overtime?from='.$date.'&to='.$date,
        )->assertOk();
        $this->assertSame('Client deadline moved up', $listed->json('data.0.remarks'));
        $this->assertSame($this->projectLabel($project), $listed->json('data.0.projectLabel'));
        $this->assertCount(2, $listed->json('data.0.entries'));
        $this->assertSame('QC', $listed->json('data.0.entries.1.activityLabel'));

        $this->assertTrue(DB::connection('portal')->table('projects_requests')
            ->where('request_id', $row->id)
            ->where('project_id', $project->id)
            ->exists());

        $this->assertTrue(Action::query()
            ->where('resource', 'requests.overtime')
            ->where('record_id', (string) $row->id)
            ->where('action_type', CoreActionType::ADD)
            ->exists());

        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'INSERT')
            ->where('resource', 'requests.overtime')
            ->where('record_id', (string) $row->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame($date, $log->payload['requestedFor']);
        $this->assertSame(3.5, $log->payload['hours']);
        $this->assertSame('Client deadline moved up', $log->payload['reason']);
        $this->assertCount(2, $log->payload['entries']);
        $this->assertSame('QC', $log->payload['entries'][1]['activityLabel']);
    }

    public function test_overtime_is_refused_for_a_day_with_no_report(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->freeDate($actor, 0);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group($date, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is no report for '.$date.', so there is no day to file overtime against.');
    }

    public function test_one_day_carries_one_overtime_request_unless_the_first_was_refused(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->reportedDate($actor, 2, $project);
        $token = $this->loginToken($actor);

        $this->insertOvertime($actor, $date, 'Pending');
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group($date, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Overtime is already filed for '.$date.'.');

        DB::connection('portal')->table('requests')
            ->where('request_date', $date)
            ->where('name', $this->memberName($actor))
            ->update(['status' => 'Rejected']);
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group($date, $project)],
        ])->assertCreated();
    }

    public function test_overtime_is_refused_outside_the_seven_day_window(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group(Carbon::today()->subDays(8)->toDateString(), $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Overtime can only be filed for today or the last seven days.');

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group(Carbon::tomorrow()->toDateString(), $project)],
        ])->assertStatus(422);
    }

    public function test_overtime_is_refused_for_a_project_the_member_is_not_on(): void
    {
        $actor = $this->activeMember();
        $own = $this->firstOwnProject($actor);
        $foreign = $this->projectNotOwnedBy($actor);
        $date = $this->reportedDate($actor, 2, $own);
        $token = $this->loginToken($actor);

        $group = $this->group($date, $foreign);

        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$group],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', '"'.$this->projectLabel($foreign).'" is not one of your projects.');
    }

    public function test_overtime_needs_a_reason_and_at_least_one_entry(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->reportedDate($actor, 2, $project);
        $token = $this->loginToken($actor);

        $blank = $this->group($date, $project);
        $blank['reason'] = '   ';
        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$blank],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Overtime needs a reason.');

        $empty = $this->group($date, $project);
        $empty['entries'] = [];
        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$empty],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add at least one entry before filing.');
    }

    public function test_the_days_list_names_the_days_overtime_already_claims(): void
    {
        $actor = $this->activeMember();
        $claimed = Carbon::today()->subDays(3)->toDateString();
        $refused = Carbon::today()->subDays(4)->toDateString();
        $this->insertOvertime($actor, $claimed, 'Approved');
        $this->insertOvertime($actor, $refused, 'Rejected');
        $token = $this->loginToken($actor);

        $days = $this->withToken($token)->getJson(
            '/api/development/portal/reports/submitted/days?from='.$refused.'&to='.$claimed,
        )->assertOk();

        // Refused means the day is open to ask again, the same rule leave follows.
        $this->assertSame([$claimed], $days->json('overtimeDays'));
    }

    public function test_the_list_is_the_members_own_overtime_and_nobody_elses(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $mine = '2024-02-13';
        $theirs = '2024-02-12';
        $leaveOn = '2024-02-11';
        $this->insertOvertime($actor, $mine, 'Approved');
        $this->insertOvertime($other, $theirs, 'Approved');
        DB::connection('portal')->table('requests')->insert([
            'request_date' => $leaveOn,
            'name' => $this->memberName($actor),
            'reason' => 'Vacation',
            'status' => 'Approved',
            'type' => 'Leave',
            'category' => '01 Vacation Leave',
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/overtime?from='.$leaveOn.'&to='.$mine,
        )->assertOk();

        $this->assertSame([$mine], $list->json('data.*.requestedOn'));
        $this->assertEquals(2, $list->json('data.0.hours'));
        $this->assertSame('approved', $list->json('data.0.status'));
        $this->assertSame('existing overtime', $list->json('data.0.remarks'));
        $this->assertSame([], $list->json('data.0.entries'));
        $this->assertArrayNotHasKey('memberName', $list->json('data.0'));
    }

    public function test_overtime_requires_authentication(): void
    {
        $this->postJson('/api/development/portal/requests/overtime', ['requests' => []])
            ->assertStatus(401);
        $this->getJson('/api/development/portal/requests/overtime')->assertStatus(401);
        $this->patchJson('/api/development/portal/requests/overtime/1', [])->assertStatus(401);
        $this->postJson('/api/development/portal/requests/overtime/1/cancel')->assertStatus(401);
    }

    public function test_a_pending_overtime_can_be_edited_and_a_decided_one_cannot(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->reportedDate($actor, 2, $project);
        $id = $this->insertOvertime($actor, $date, 'Pending');
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)->patchJson('/api/development/portal/requests/overtime/'.$id, [
            'requestDate' => $date,
            'reason' => 'Deadline moved again',
            'entries' => [[
                'projectLabel' => $this->projectLabel($project),
                'activityLabel' => 'QC',
                'hoursRendered' => 3,
                'elementChange' => 1,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('data.requestedOn', $date)
            ->assertJsonPath('data.remarks', 'Deadline moved again')
            ->assertJsonPath('data.entries.0.activityLabel', 'QC')
            ->assertJsonPath('data.hours', 3);

        $this->assertTrue(Action::query()
            ->where('resource', 'requests.overtime')
            ->where('record_id', (string) $id)
            ->where('action_type', CoreActionType::EDIT)
            ->exists());

        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'PATCH')
            ->where('resource', 'requests.overtime')
            ->where('record_id', (string) $id)
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame($date, $log->payload['requestedFor']);
        // A whole-number float round-trips through the JSON column as an integer.
        $this->assertSame(3, $log->payload['hours']);
        $this->assertSame('Deadline moved again', $log->payload['reason']);

        DB::connection('portal')->table('requests')->where('id', $id)->update(['status' => 'Approved']);
        Cache::flush();

        $this->withToken($token)->patchJson('/api/development/portal/requests/overtime/'.$id, [
            'requestDate' => $date,
            'reason' => 'Too late',
            'entries' => [[
                'projectLabel' => $this->projectLabel($project),
                'activityLabel' => 'QC',
                'hoursRendered' => 3,
                'elementChange' => 0,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a request nobody has decided on yet can be changed.');

        $this->withToken($token)
            ->postJson('/api/development/portal/requests/overtime/'.$id.'/cancel')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a request nobody has decided on yet can be changed.');
    }

    public function test_a_pending_request_can_be_cancelled_and_the_day_comes_back(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->reportedDate($actor, 2, $project);
        $id = $this->insertOvertime($actor, $date, 'Pending');
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)
            ->postJson('/api/development/portal/requests/overtime/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // The row stays: the history records what was asked for, not only what stood.
        $this->assertSame('Cancelled', DB::connection('portal')->table('requests')
            ->where('id', $id)->value('status'));

        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'PATCH')
            ->where('resource', 'requests.overtime')
            ->where('record_id', (string) $id)
            ->first();
        $this->assertNotNull($log);
        $this->assertIsArray($log->payload);
        $this->assertSame($date, $log->payload['requestedFor']);
        $this->assertSame('Cancelled', $log->payload['status']);

        // And the day is free again, so it can be asked for a second time.
        Cache::flush();
        $this->withToken($token)->postJson('/api/development/portal/requests/overtime', [
            'requests' => [$this->group($date, $project)],
        ])->assertCreated();
    }

    public function test_somebody_elses_overtime_is_not_found_rather_than_forbidden(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $date = Carbon::today()->subDays(2)->toDateString();
        $id = $this->insertOvertime($other, $date, 'Pending');
        $token = $this->loginToken($actor);

        // Whose request it is is not this member's to learn.
        $this->withToken($token)
            ->postJson('/api/development/portal/requests/overtime/'.$id.'/cancel')
            ->assertStatus(404);
        $this->withToken($token)->patchJson('/api/development/portal/requests/overtime/'.$id, [
            'requestDate' => $date,
            'reason' => 'Deadline moved again',
            'entries' => [[
                'projectLabel' => '260005 IKAIKA Portal V2',
                'activityLabel' => 'QC',
                'hoursRendered' => 3,
                'elementChange' => 1,
            ]],
        ])->assertStatus(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $date, object $project): array
    {
        return [
            'requestDate' => $date,
            'reason' => 'Rush deliverable',
            'entries' => [[
                'projectLabel' => $this->projectLabel($project),
                'activityLabel' => 'REVIT MODELING',
                'hoursRendered' => 2,
                'elementChange' => 0,
            ]],
        ];
    }

    /**
     * A day inside the window that the member has filed a report for, created here so the fixture
     * database's own timesheets cannot decide whether the test passes.
     */
    private function reportedDate(Employee $actor, int $daysAgo, object $project): string
    {
        $date = $this->freeDate($actor, $daysAgo);
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $date,
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'approval' => 'Approved',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $actor->getKey(),
            'user_report_id' => $id,
        ]);
        DB::connection('portal')->table('projects_user_reports')->insert([
            'project_id' => $project->id,
            'user_report_id' => $id,
        ]);
        Cache::flush();

        return $date;
    }

    private function insertOvertime(Employee $actor, string $date, string $status): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($actor),
            'no_of_hours' => 2,
            'reason' => 'existing overtime',
            'status' => $status,
            'type' => 'Overtime',
        ]);
    }

    /**
     * A day inside the filing window with no report and no request of any kind on it.
     */
    private function freeDate(Employee $actor, int $daysAgo): string
    {
        $name = $this->memberName($actor);
        for ($offset = $daysAgo; $offset <= 7; $offset++) {
            $candidate = Carbon::today()->subDays($offset)->toDateString();
            $reported = DB::connection('portal')->table('user_reports')
                ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
                ->where('employees_user_reports.employee_id', $actor->getKey())
                ->where('user_reports.report_date', $candidate)
                ->exists();
            $requested = DB::connection('portal')->table('requests')
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                ->where(function ($query) use ($candidate): void {
                    $query->where('request_date', $candidate)
                        ->orWhere('original_work_day', $candidate)
                        ->orWhere('offset_work_day', $candidate);
                })
                ->exists();
            if (! $reported && ! $requested) {
                return $candidate;
            }
        }

        $this->fail('No free day was available inside the overtime window.');
    }

    private function projectLabel(object $project): string
    {
        return PortalSubmittedReportPresenter::projectLabel(
            $project->project_number ?? null,
            $project->project_name ?? null,
        );
    }

    private function memberName(Employee $actor): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereHas('projects')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function firstOwnProject(Employee $actor): object
    {
        $row = DB::connection('portal')->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $actor->getKey())
            ->whereNotNull('projects.project_name')
            ->select(['projects.id', 'projects.project_number', 'projects.project_name'])
            ->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function projectNotOwnedBy(Employee $actor): object
    {
        $row = DB::connection('portal')->table('projects')
            ->whereNotNull('project_name')
            ->whereNotIn('id', DB::connection('portal')->table('employees_projects')
                ->where('employee_id', $actor->getKey())
                ->pluck('project_id'))
            ->select(['id', 'project_number', 'project_name'])
            ->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('id', '!=', $actor->id)
            ->whereNotNull('first_name')
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
}
