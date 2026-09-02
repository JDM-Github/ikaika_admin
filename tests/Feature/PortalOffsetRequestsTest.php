<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalOffsetRequestsTest extends TestCase
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

    public function test_offset_is_filed_as_a_work_day_and_a_day_off(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $work = $this->freeDate($actor, 2);
        $dayOff = $this->freeDayOff($actor, $work, 1);
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [[
                'workDate' => $work,
                'dayOffDate' => $dayOff,
                'reason' => 'Saturday coverage',
                'entries' => [
                    [
                        'projectLabel' => $this->projectLabel($project),
                        'activityLabel' => 'REVIT MODELING',
                        'hoursRendered' => 5,
                        'elementChange' => 4,
                    ],
                    [
                        'projectLabel' => $this->projectLabel($project),
                        'activityLabel' => 'QC',
                        'hoursRendered' => 3,
                        'elementChange' => 0,
                    ],
                ],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('section', 'requests')
            ->assertJsonPath('resource', 'offset')
            ->assertJsonPath('data.0.workOn', $work)
            ->assertJsonPath('data.0.dayOffOn', $dayOff)
            ->assertJsonPath('data.0.hours', 8)
            ->assertJsonPath('data.0.status', 'pending');

        $row = DB::connection('portal')->table('requests')
            ->where('id', (int) $response->json('data.0.id'))
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('Offset', $row->type);
        $this->assertSame($this->memberName($actor), $row->name);
        $this->assertSame($work, $this->calendarDate($row->original_work_day));
        $this->assertSame($dayOff, $this->calendarDate($row->offset_work_day));
        $this->assertSame($work, $this->calendarDate($row->request_date));
        $this->assertStringContainsString('Saturday coverage', (string) $row->reason);
        $this->assertStringContainsString('REVIT MODELING', (string) $row->reason);

        $this->assertTrue(DB::connection('portal')->table('projects_requests')
            ->where('request_id', $row->id)
            ->where('project_id', $project->id)
            ->exists());

        $this->assertTrue(Action::query()
            ->where('resource', 'requests.offset')
            ->where('record_id', (string) $row->id)
            ->where('action_type', CoreActionType::ADD)
            ->exists());
    }

    public function test_offset_is_refused_when_either_day_is_already_taken(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $work = $this->freeDate($actor, 2);
        $dayOff = $this->freeDayOff($actor, $work, 1);
        $token = $this->loginToken($actor);

        $this->insertOffset($actor, $work, $dayOff, 'Pending');
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($work, $dayOff, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Offset is already filed for '.$work.'.');

        DB::connection('portal')->table('requests')
            ->where('original_work_day', $work)
            ->where('name', $this->memberName($actor))
            ->update(['status' => 'Rejected']);
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($work, $dayOff, $project)],
        ])->assertCreated();
    }

    public function test_offset_is_refused_on_a_leave_day(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $work = $this->freeDate($actor, 2);
        $dayOff = $this->freeDayOff($actor, $work, 1);
        $token = $this->loginToken($actor);

        DB::connection('portal')->table('requests')->insert([
            'request_date' => $dayOff,
            'name' => $this->memberName($actor),
            'status' => 'Pending',
            'type' => 'Leave',
        ]);
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($work, $dayOff, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have leave filed for '.$dayOff.'.');
    }

    public function test_offset_is_refused_outside_each_date_window(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $token = $this->loginToken($actor);
        $dayOff = Carbon::today()->addDays(3)->toDateString();

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group(Carbon::today()->subDays(8)->toDateString(), $dayOff, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The day to work must be today or one of the last seven days.');

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group(Carbon::tomorrow()->toDateString(), $dayOff, $project)],
        ])->assertStatus(422);

        $work = $this->freeDate($actor, 1);
        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($work, Carbon::today()->addDays(15)->toDateString(), $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The day off must fall between seven days ago and two weeks ahead.');
    }

    public function test_offset_is_refused_when_the_two_dates_are_the_same(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $date = $this->freeDate($actor, 1);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($date, $date, $project)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The day off has to be a different day from the day worked.');
    }

    public function test_offset_is_refused_for_a_project_the_member_is_not_on(): void
    {
        $actor = $this->activeMember();
        $own = $this->firstOwnProject($actor);
        $foreign = $this->projectNotOwnedBy($actor);
        $work = $this->freeDate($actor, 2);
        $dayOff = $this->freeDayOff($actor, $work, 1);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$this->group($work, $dayOff, $foreign)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', '"'.$this->projectLabel($foreign).'" is not one of your projects.');

        $this->assertNotSame($own->id, $foreign->id);
    }

    public function test_offset_needs_a_reason_and_at_least_one_entry(): void
    {
        $actor = $this->activeMember();
        $project = $this->firstOwnProject($actor);
        $work = $this->freeDate($actor, 2);
        $dayOff = $this->freeDayOff($actor, $work, 1);
        $token = $this->loginToken($actor);

        $blank = $this->group($work, $dayOff, $project);
        $blank['reason'] = '   ';
        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$blank],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Offset needs a reason.');

        $empty = $this->group($work, $dayOff, $project);
        $empty['entries'] = [];
        $this->withToken($token)->postJson('/api/development/portal/requests/offset', [
            'requests' => [$empty],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add at least one entry before filing.');
    }

    public function test_the_days_list_names_both_days_of_an_offset_unless_it_was_refused(): void
    {
        $actor = $this->activeMember();
        $work = Carbon::today()->subDays(3)->toDateString();
        $dayOff = Carbon::today()->subDays(1)->toDateString();
        $refusedWork = Carbon::today()->subDays(5)->toDateString();
        $refusedOff = Carbon::today()->subDays(4)->toDateString();
        $this->insertOffset($actor, $work, $dayOff, 'Approved');
        $this->insertOffset($actor, $refusedWork, $refusedOff, 'Rejected');
        $token = $this->loginToken($actor);

        $days = $this->withToken($token)->getJson(
            '/api/development/portal/reports/submitted/days?from='.$refusedWork.'&to='.$dayOff,
        )->assertOk();

        $offsetDays = $days->json('offsetDays');
        $this->assertIsArray($offsetDays);
        $this->assertContains($work, $offsetDays);
        $this->assertContains($dayOff, $offsetDays);
        $this->assertNotContains($refusedWork, $offsetDays);
        $this->assertNotContains($refusedOff, $offsetDays);
    }

    public function test_the_list_is_the_members_own_offset_and_nobody_elses(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $work = '2024-02-13';
        $dayOff = '2024-02-15';
        $theirWork = '2024-02-12';
        $theirOff = '2024-02-14';
        $this->insertOffset($actor, $work, $dayOff, 'Approved');
        $this->insertOffset($other, $theirWork, $theirOff, 'Approved');
        DB::connection('portal')->table('requests')->insert([
            'request_date' => '2024-02-10',
            'name' => $this->memberName($actor),
            'no_of_hours' => 2,
            'reason' => 'existing overtime',
            'status' => 'Approved',
            'type' => 'Overtime',
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/offset?from='.$theirWork.'&to='.$dayOff,
        )->assertOk();

        $this->assertSame([$work], $list->json('data.*.requestedOn'));
        $this->assertSame([$dayOff], $list->json('data.*.dayOffOn'));
        $this->assertEquals(8, $list->json('data.0.hours'));
        $this->assertSame('approved', $list->json('data.0.status'));
        $this->assertSame(
            ['id', 'createdOn', 'requestedOn', 'dayOffOn', 'hours', 'remarks', 'status', 'approverRemarks'],
            array_keys($list->json('data.0')),
        );
        $this->assertArrayNotHasKey('memberName', $list->json('data.0'));
    }

    public function test_offset_requires_authentication(): void
    {
        $this->postJson('/api/development/portal/requests/offset', ['requests' => []])
            ->assertStatus(401);
        $this->getJson('/api/development/portal/requests/offset')->assertStatus(401);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $work, string $dayOff, object $project): array
    {
        return [
            'workDate' => $work,
            'dayOffDate' => $dayOff,
            'reason' => 'Weekend coverage',
            'entries' => [[
                'projectLabel' => $this->projectLabel($project),
                'activityLabel' => 'REVIT MODELING',
                'hoursRendered' => 8,
                'elementChange' => 0,
            ]],
        ];
    }

    private function insertOffset(Employee $actor, string $work, string $dayOff, string $status): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $work,
            'name' => $this->memberName($actor),
            'no_of_hours' => 8,
            'original_work_day' => $work,
            'offset_work_day' => $dayOff,
            'offset_hrs' => 8,
            'reason' => 'existing offset',
            'status' => $status,
            'type' => 'Offset',
        ]);
    }

    /**
     * A day inside the work window with no report and no request of any kind on it.
     */
    private function freeDate(Employee $actor, int $daysAgo): string
    {
        $name = $this->memberName($actor);
        for ($offset = $daysAgo; $offset <= 7; $offset++) {
            $candidate = Carbon::today()->subDays($offset)->toDateString();
            if ($this->isOccupied($actor, $name, $candidate)) {
                continue;
            }

            return $candidate;
        }

        $this->fail('No free day was available inside the offset work window.');
    }

    /**
     * A day off inside the booking window that is not the work day and is otherwise free.
     */
    private function freeDayOff(Employee $actor, string $work, int $daysAhead): string
    {
        $name = $this->memberName($actor);
        for ($offset = $daysAhead; $offset <= 14; $offset++) {
            $candidate = Carbon::today()->addDays($offset)->toDateString();
            if ($candidate === $work || $this->isOccupied($actor, $name, $candidate)) {
                continue;
            }

            return $candidate;
        }
        for ($offset = 0; $offset <= 7; $offset++) {
            $candidate = Carbon::today()->subDays($offset)->toDateString();
            if ($candidate === $work || $this->isOccupied($actor, $name, $candidate)) {
                continue;
            }

            return $candidate;
        }

        $this->fail('No free day off was available inside the offset window.');
    }

    private function isOccupied(Employee $actor, string $name, string $date): bool
    {
        $reported = DB::connection('portal')->table('user_reports')
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $actor->getKey())
            ->where('user_reports.report_date', $date)
            ->exists();
        $requested = DB::connection('portal')->table('requests')
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->where(function ($query) use ($date): void {
                $query->where('request_date', $date)
                    ->orWhere('original_work_day', $date)
                    ->orWhere('offset_work_day', $date);
            })
            ->exists();

        return $reported || $requested;
    }

    private function calendarDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return substr($value, 0, 10);
        }

        return null;
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
