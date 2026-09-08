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

class PortalLeaveRequestsTest extends TestCase
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

    public function test_a_range_becomes_one_row_per_day_under_one_reason(): void
    {
        $actor = $this->activeMember();
        $start = $this->freeDate($actor, 3);
        $end = Carbon::parse($start)->addDays(2)->toDateString();
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $start,
            'endDate' => $end,
            'reason' => 'Down with the flu',
        ]);

        $response->assertCreated()
            ->assertJsonPath('section', 'requests')
            ->assertJsonPath('resource', 'leave')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.requestedOn', $start)
            ->assertJsonPath('data.0.leaveTypeLabel', '02 Sick Leave')
            ->assertJsonPath('data.0.status', 'pending');

        $rows = DB::connection('portal')->table('requests')
            ->whereIn('id', array_map('intval', $response->json('data.*.id')))
            ->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame('Leave', $row->type);
            // The table has no leave-type column of its own; category is where it lands.
            $this->assertSame('02 Sick Leave', $row->category);
            $this->assertSame('Down with the flu', $row->reason);
            $this->assertSame($this->memberName($actor), $row->name);
            $this->assertNull($row->no_of_hours);
            $this->assertTrue(Action::query()
                ->where('resource', 'requests.leave')
                ->where('record_id', (string) $row->id)
                ->where('action_type', CoreActionType::ADD)
                ->exists());
        }

        $from = Carbon::createFromFormat('Y-m-d', $start);
        $to = Carbon::createFromFormat('Y-m-d', $end);
        $this->assertNotFalse($from);
        $this->assertNotFalse($to);
        $log = PortalLog::query()
            ->where('employee_id', $actor->getKey())
            ->where('action', 'INSERT')
            ->where('resource', 'requests.leave')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame(
            '{UserName|You} filed a Sick Leave request from '.$from->format('l, F j, Y').' to '.$to->format('l, F j, Y'),
            $log->message,
        );
    }

    public function test_one_day_is_a_range_of_one(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family matter',
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_day_already_on_leave_is_refused_unless_that_leave_was_refused(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $token = $this->loginToken($actor);
        $this->insertLeave($actor, $date, 'Pending');
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family matter',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already have leave filed for '.$date.'.');

        DB::connection('portal')->table('requests')
            ->where('request_date', $date)
            ->where('name', $this->memberName($actor))
            ->update(['status' => 'Rejected']);
        Cache::flush();

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family matter',
        ])->assertCreated();
    }

    public function test_a_day_already_reported_cannot_be_taken_as_leave(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $this->insertReport($actor, $date);
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Down with the flu',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already filed a report for '.$date.', so that day was worked.');
    }

    public function test_the_range_the_type_and_the_reason_are_all_checked(): void
    {
        $actor = $this->activeMember();
        $start = $this->freeDate($actor, 3);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $start,
            'endDate' => Carbon::parse($start)->subDay()->toDateString(),
            'reason' => 'Down with the flu',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The end date cannot come before the start date.');

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $start,
            'endDate' => Carbon::parse($start)->addDays(40)->toDateString(),
            'reason' => 'Down with the flu',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One application cannot cover more than 31 days.');

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => 'Sabbatical',
            'startDate' => $start,
            'endDate' => $start,
            'reason' => 'Down with the flu',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose one of the leave types the form offers.');

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $start,
            'endDate' => $start,
            'reason' => '   ',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Leave needs a reason.');

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => Carbon::today()->addMonths(14)->toDateString(),
            'endDate' => Carbon::today()->addMonths(14)->toDateString(),
            'reason' => 'Booked far ahead',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Leave cannot be booked more than a year ahead.');
    }

    public function test_the_list_is_the_members_own_leave_and_nobody_elses(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $mine = $this->freeDate($actor, 3);
        $theirs = Carbon::parse($mine)->subDay()->toDateString();
        $this->insertLeave($actor, $mine, 'Approved', '03 Emergency Leave');
        $this->insertLeave($other, $theirs, 'Approved', '01 Vacation Leave');
        // A legacy untyped row carrying hours is extra time worked, not a day away.
        $worked = Carbon::parse($mine)->subDays(2)->toDateString();
        DB::connection('portal')->table('requests')->insert([
            'request_date' => $worked,
            'name' => $this->memberName($actor),
            'no_of_hours' => 4,
            'reason' => 'Manual encoding',
            'status' => 'Approved',
        ]);
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/leave?from='.$worked.'&to='.$mine,
        )->assertOk();

        $this->assertSame([$mine], $list->json('data.*.requestedOn'));
        $this->assertSame('03 Emergency Leave', $list->json('data.0.leaveTypeLabel'));
        $this->assertSame('approved', $list->json('data.0.status'));
        $this->assertContains('01 Vacation Leave', $list->json('types'));
        // No memberName on an own row: only the approval queue needs to say whose.
        $this->assertNull($list->json('data.0.memberName'));
    }

    public function test_the_list_names_the_days_a_report_already_covers(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $this->insertReport($actor, $date);
        Cache::flush();
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/leave?from='.$date.'&to='.$date,
        )->assertOk();

        $this->assertSame([$date], $list->json('reportedDays'));
    }

    public function test_a_day_already_gone_cannot_be_asked_for(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $yesterday = Carbon::today()->subDay()->toDateString();

        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '02 Sick Leave',
            'startDate' => $yesterday,
            'endDate' => $yesterday,
            'reason' => 'Down with the flu',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Leave starts today or later.');
    }

    public function test_a_pending_request_can_be_edited_and_a_decided_one_cannot(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $moved = Carbon::parse($date)->addDay()->toDateString();
        $id = $this->insertLeave($actor, $date, 'Pending');
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)->patchJson('/api/development/portal/requests/leave/'.$id, [
            'leaveType' => '03 Emergency Leave',
            'requestDate' => $moved,
            'reason' => 'Family matter',
        ])
            ->assertOk()
            ->assertJsonPath('data.requestedOn', $moved)
            ->assertJsonPath('data.leaveTypeLabel', '03 Emergency Leave')
            ->assertJsonPath('data.remarks', 'Family matter');

        $this->assertTrue(Action::query()
            ->where('resource', 'requests.leave')
            ->where('record_id', (string) $id)
            ->where('action_type', CoreActionType::EDIT)
            ->exists());

        DB::connection('portal')->table('requests')->where('id', $id)->update(['status' => 'Approved']);
        Cache::flush();

        $this->withToken($token)->patchJson('/api/development/portal/requests/leave/'.$id, [
            'leaveType' => '01 Vacation Leave',
            'requestDate' => $moved,
            'reason' => 'Family matter',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a request nobody has decided on yet can be changed.');
    }

    public function test_a_pending_request_can_be_cancelled_and_the_day_comes_back(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor, 3);
        $id = $this->insertLeave($actor, $date, 'Pending');
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)
            ->postJson('/api/development/portal/requests/leave/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // The row stays: the history records what was asked for, not only what stood.
        $this->assertSame('Cancelled', DB::connection('portal')->table('requests')
            ->where('id', $id)->value('status'));

        // And the day is free again, so it can be asked for a second time.
        Cache::flush();
        $this->withToken($token)->postJson('/api/development/portal/requests/leave', [
            'leaveType' => '01 Vacation Leave',
            'startDate' => $date,
            'endDate' => $date,
            'reason' => 'Family matter',
        ])->assertCreated();
    }

    public function test_somebody_elses_request_is_not_found_rather_than_forbidden(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $id = $this->insertLeave($other, Carbon::today()->addDays(4)->toDateString(), 'Pending');
        $token = $this->loginToken($actor);

        // Whose request it is is not this member's to learn.
        $this->withToken($token)
            ->postJson('/api/development/portal/requests/leave/'.$id.'/cancel')
            ->assertStatus(404);
        $this->withToken($token)->patchJson('/api/development/portal/requests/leave/'.$id, [
            'leaveType' => '01 Vacation Leave',
            'requestDate' => Carbon::today()->addDays(4)->toDateString(),
            'reason' => 'Family matter',
        ])->assertStatus(404);
    }

    public function test_leave_requires_authentication(): void
    {
        $this->getJson('/api/development/portal/requests/leave')->assertStatus(401);
        $this->postJson('/api/development/portal/requests/leave', [])->assertStatus(401);
        $this->patchJson('/api/development/portal/requests/leave/1', [])->assertStatus(401);
        $this->postJson('/api/development/portal/requests/leave/1/cancel')->assertStatus(401);
    }

    private function insertLeave(
        Employee $actor,
        string $date,
        string $status,
        string $type = '01 Vacation Leave',
    ): int {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($actor),
            'reason' => 'existing leave',
            'status' => $status,
            'type' => 'Leave',
            'category' => $type,
        ]);
    }

    private function insertReport(Employee $actor, string $date): int
    {
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

        return $id;
    }

    /**
     * A near-future day with no report and no request on it, so the seeded timesheets cannot
     * decide whether the test passes.
     */
    private function freeDate(Employee $actor, int $daysAhead): string
    {
        $name = $this->memberName($actor);
        for ($offset = $daysAhead; $offset < $daysAhead + 200; $offset++) {
            $candidate = Carbon::today()->addDays($offset)->toDateString();
            $end = Carbon::today()->addDays($offset + 2)->toDateString();
            $reported = DB::connection('portal')->table('user_reports')
                ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
                ->where('employees_user_reports.employee_id', $actor->getKey())
                ->whereBetween('user_reports.report_date', [$candidate, $end])
                ->exists();
            $requested = DB::connection('portal')->table('requests')
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                ->where(function ($query) use ($candidate, $end): void {
                    $query->whereBetween('request_date', [$candidate, $end])
                        ->orWhereBetween('original_work_day', [$candidate, $end])
                        ->orWhereBetween('offset_work_day', [$candidate, $end]);
                })
                ->exists();
            if (! $reported && ! $requested) {
                return $candidate;
            }
        }

        $this->fail('No free day was available inside the leave window.');
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
            ->first();
        $this->assertNotNull($employee);

        return $employee;
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
