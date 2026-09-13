<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalCalendarEventsTest extends TestCase
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

    public function test_consecutive_leave_days_are_one_spanning_event(): void
    {
        $actor = $this->activeMember();
        $start = $this->freeDate($actor);
        $end = Carbon::parse($start)->addDay()->toDateString();
        $this->insertLeave($actor, $start, 'Pending');
        $this->insertLeave($actor, $end, 'Pending');
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$start.'&to='.$end,
        );

        $response->assertOk();
        $leave = collect($response->json('data'))->firstWhere('kind', 'leave');
        $this->assertIsArray($leave);
        $this->assertSame($start, $leave['startsOn']);
        $this->assertSame($end, $leave['endsOn']);
        $this->assertStringContainsString(': Sick Leave', (string) $leave['title']);
        $this->assertCount(1, collect($response->json('data'))->where('kind', 'leave'));
    }

    public function test_a_leave_request_date_is_an_event(): void
    {
        $actor = $this->activeMember();
        $date = $this->freeDate($actor);
        $this->insertLeave($actor, $date, 'Pending');
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$date.'&to='.$date,
        );

        $response->assertOk()
            ->assertJsonPath('section', 'calendar')
            ->assertJsonPath('resource', 'events');

        $leave = collect($response->json('data'))->firstWhere('kind', 'leave');
        $this->assertIsArray($leave);
        $this->assertSame($date, $leave['startsOn']);
        $this->assertStringContainsString(': Sick Leave', (string) $leave['title']);
        $this->assertArrayNotHasKey('endsOn', $leave);
        $this->assertNull($leave['startsAt']);
    }

    public function test_cancelled_and_refused_request_dates_are_not_events(): void
    {
        $actor = $this->activeMember();
        $cancelled = $this->freeDate($actor);
        $refused = Carbon::parse($cancelled)->addDays(1)->toDateString();
        $this->insertLeave($actor, $cancelled, 'Cancelled');
        $this->insertLeave($actor, $refused, 'Rejected');
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$cancelled.'&to='.$refused,
        );

        $response->assertOk();
        $startsOn = collect($response->json('data'))
            ->where('kind', 'leave')
            ->pluck('startsOn')
            ->all();
        $this->assertNotContains($cancelled, $startsOn);
        $this->assertNotContains($refused, $startsOn);
    }

    public function test_an_offset_pair_lands_on_both_days(): void
    {
        $actor = $this->activeMember();
        $work = $this->freeDate($actor);
        $off = Carbon::parse($work)->addDays(2)->toDateString();
        DB::connection('portal')->table('requests')->insert([
            'request_date' => $work,
            'name' => $this->memberName($actor),
            'reason' => 'Saturday coverage',
            'status' => 'Pending',
            'type' => 'Offset',
            'original_work_day' => $work,
            'offset_work_day' => $off,
        ]);
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$work.'&to='.$off,
        );

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->implode(' ');
        $this->assertStringContainsString('offset work', $titles);
        $this->assertStringContainsString('offset day off', $titles);
        $startsOn = collect($response->json('data'))->pluck('startsOn')->all();
        $this->assertContains($work, $startsOn);
        $this->assertContains($off, $startsOn);
    }

    public function test_unauthenticated_reads_are_refused(): void
    {
        $this->getJson('/api/development/portal/calendar/events')->assertStatus(401);
    }

    public function test_invalid_dates_are_refused(): void
    {
        $token = $this->loginToken($this->activeMember());

        $this->withToken($token)
            ->getJson('/api/development/portal/calendar/events?from=09-09-2026')
            ->assertStatus(422);
    }

    public function test_a_member_can_create_an_everyone_event(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor);
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->postJson('/api/development/portal/calendar/events', [
            'title' => 'All hands',
            'details' => 'Town hall',
            'startsOn' => $day,
            'startsAt' => '16:00',
            'endsOn' => $day,
            'endsAt' => '17:00',
            'category' => 'company_event',
            'audience' => 'everyone',
        ]);

        $response->assertCreated()
            ->assertJsonPath('section', 'calendar')
            ->assertJsonPath('resource', 'events')
            ->assertJsonPath('data.title', 'All hands')
            ->assertJsonPath('data.startsOn', $day)
            ->assertJsonPath('data.startsAt', '16:00')
            ->assertJsonPath('data.kind', 'event')
            ->assertJsonPath('data.detail', 'Town hall');
        $this->assertStringStartsWith('calendar-', (string) $response->json('data.id'));

        $listed = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$day.'&to='.$day,
        );
        $listed->assertOk();
        $match = collect($listed->json('data'))->firstWhere('title', 'All hands');
        $this->assertIsArray($match);
        $this->assertSame('16:00', $match['startsAt']);
    }

    public function test_a_project_event_uses_project_kind(): void
    {
        $actor = $this->activeMember();
        $day = $this->freeDate($actor);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/calendar/events', [
            'title' => 'Cutover',
            'startsOn' => $day,
            'category' => 'project',
            'audience' => 'everyone',
        ])->assertCreated()->assertJsonPath('data.kind', 'project');
    }

    public function test_a_department_event_is_hidden_from_other_departments(): void
    {
        $actor = $this->activeMember();
        $department = trim((string) $actor->department);
        $this->assertNotSame('', $department);
        $outsider = $this->memberOutsideDepartment($actor, $department);
        $day = $this->freeDate($actor);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/calendar/events', [
            'title' => 'Dept standup',
            'startsOn' => $day,
            'category' => 'company_event',
            'audience' => 'department',
            'departments' => [$department],
        ])->assertCreated();

        $outsiderToken = $this->loginToken($outsider);
        $listed = $this->withToken($outsiderToken)->getJson(
            '/api/development/portal/calendar/events?from='.$day.'&to='.$day,
        );
        $listed->assertOk();
        $titles = collect($listed->json('data'))->pluck('title')->all();
        $this->assertNotContains('Dept standup', $titles);

        $own = $this->withToken($token)->getJson(
            '/api/development/portal/calendar/events?from='.$day.'&to='.$day,
        );
        $this->assertContains('Dept standup', collect($own->json('data'))->pluck('title')->all());
    }

    public function test_a_members_event_is_hidden_from_anyone_not_named(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherMember($actor);
        $day = $this->freeDate($actor);
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/calendar/events', [
            'title' => 'Private review',
            'startsOn' => $day,
            'category' => 'company_event',
            'audience' => 'members',
            'memberIds' => [(int) $actor->getKey()],
        ])->assertCreated();

        $otherToken = $this->loginToken($other);
        $listed = $this->withToken($otherToken)->getJson(
            '/api/development/portal/calendar/events?from='.$day.'&to='.$day,
        );
        $this->assertNotContains('Private review', collect($listed->json('data'))->pluck('title')->all());
    }

    public function test_creating_an_event_without_a_title_returns_422(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/calendar/events', [
            'startsOn' => $this->freeDate($actor),
            'category' => 'company_event',
            'audience' => 'everyone',
        ])->assertStatus(422)->assertJsonPath('message', 'An event needs a title.');
    }

    public function test_unauthenticated_creates_are_refused(): void
    {
        $this->postJson('/api/development/portal/calendar/events', [
            'title' => 'Nope',
            'startsOn' => '2026-09-10',
            'category' => 'company_event',
            'audience' => 'everyone',
        ])->assertStatus(401);
    }

    public function test_event_options_list_departments_and_members_without_email(): void
    {
        $token = $this->loginToken($this->activeMember());

        $response = $this->withToken($token)->getJson('/api/development/portal/calendar/event-options');

        $response->assertOk()
            ->assertJsonPath('section', 'calendar')
            ->assertJsonPath('resource', 'event-options');
        $departments = $response->json('data.departments');
        $members = $response->json('data.members');
        $this->assertIsArray($departments);
        $this->assertIsArray($members);
        $this->assertNotEmpty($members);
        $first = $members[0];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayNotHasKey('email', $first);
    }

    public function test_unauthenticated_event_options_are_refused(): void
    {
        $this->getJson('/api/development/portal/calendar/event-options')->assertStatus(401);
    }

    private function insertLeave(Employee $actor, string $date, string $status): int
    {
        return (int) DB::connection('portal')->table('requests')->insertGetId([
            'request_date' => $date,
            'name' => $this->memberName($actor),
            'reason' => 'calendar leave',
            'status' => $status,
            'type' => 'Leave',
            'category' => '02 Sick Leave',
        ]);
    }

    private function freeDate(Employee $actor): string
    {
        $name = $this->memberName($actor);
        for ($offset = 3; $offset < 200; $offset++) {
            $candidate = Carbon::today()->addDays($offset)->toDateString();
            $requested = DB::connection('portal')->table('requests')
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                ->where(function ($query) use ($candidate): void {
                    $query->where('request_date', $candidate)
                        ->orWhere('original_work_day', $candidate)
                        ->orWhere('offset_work_day', $candidate);
                })
                ->exists();
            if (! $requested) {
                return $candidate;
            }
        }

        $this->fail('No free day was available for the calendar test.');
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

    private function otherMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->where('id', '!=', $actor->getKey())
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function memberOutsideDepartment(Employee $actor, string $department): Employee
    {
        $employee = Employee::query()
            ->where('id', '!=', $actor->getKey())
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw('LOWER(TRIM(COALESCE(department, ""))) != ?', [strtolower($department)])
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
}
