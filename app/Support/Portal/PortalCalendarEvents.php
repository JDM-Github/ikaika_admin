<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\LeaveRequest;
use App\Modules\Portal\Models\Reimbursement;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar / Events: company holidays, live request dates, and member-created events.
 *
 * The Event Calendar is the same holiday source the pickers already use, with request days drawn
 * as events so a filed leave, overtime, offset pair, or reimbursement lands on the grid. Cancelled
 * and refused rows stay off it: those days are free again, the same rule occupancy follows.
 * Custom rows live in calendar_events and are visible by audience: everyone, a department, or
 * named members. The creator always sees their own event.
 */
final class PortalCalendarEvents
{
    public const CACHE_TTL_SECONDS = 30;

    public const CACHE_VERSION_KEY = 'portal:calendar:events:version';

    private const OPTIONS_CACHE_VERSION_KEY = 'portal:calendar:event-options:version';

    private const CORE_RESOURCE = 'calendar.events';

    private const MAX_TITLE = 255;

    private const MAX_DETAILS = 2000;

    private const MAX_SPAN_DAYS = 366;

    private const CATEGORY_COMPANY = 'company_event';

    private const CATEGORY_PROJECT = 'project';

    private const AUDIENCE_EVERYONE = 'everyone';

    private const AUDIENCE_DEPARTMENT = 'department';

    private const AUDIENCE_MEMBERS = 'members';

    /**
     * @var list<string>
     */
    private const CATEGORIES = [self::CATEGORY_COMPANY, self::CATEGORY_PROJECT];

    /**
     * @var list<string>
     */
    private const AUDIENCES = [self::AUDIENCE_EVERYONE, self::AUDIENCE_DEPARTMENT, self::AUDIENCE_MEMBERS];

    public function __construct(
        private readonly PortalHolidays $holidays,
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{events: int, holidays: int, leave: int, requests: int},
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        $holidays = $this->holidays->list($request);
        $from = $holidays['range']['from'];
        $to = $holidays['range']['to'];

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:calendar:events:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($holidays, $from, $to, $actor): array {
            return $this->build($holidays, $from, $to, $actor);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @param  array{data: list<array<string, mixed>>, range: array{from: string, to: string}}  $holidays
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{events: int, holidays: int, leave: int, requests: int},
     *     range: array{from: string, to: string}
     * }
     */
    private function build(array $holidays, string $from, string $to, Employee $actor): array
    {
        $data = [];
        foreach ($holidays['data'] as $holiday) {
            $type = is_string($holiday['type'] ?? null) ? $holiday['type'] : 'special';
            $data[] = PortalCalendarEventPresenter::event(
                'holiday-'.(string) ($holiday['id'] ?? ''),
                is_string($holiday['name'] ?? null) ? $holiday['name'] : 'Holiday',
                is_string($holiday['date'] ?? null) ? $holiday['date'] : $from,
                'holiday',
                null,
                $type === 'regular' ? 'Regular holiday' : 'Special holiday',
            );
        }

        $holidayCount = count($data);
        $leaveCount = 0;
        $requestCount = 0;
        $leaveDays = [];

        foreach ($this->requestRows($from, $to) as $row) {
            if (PortalSubmittedReportPresenter::isCancelled($row->status ?? null)
                || PortalSubmittedReportPresenter::isRefused($row->status ?? null)) {
                continue;
            }

            $name = trim((string) ($row->name ?? ''));
            $who = $name !== '' ? $name : 'Member';
            $kind = PortalSubmittedReportPresenter::occupancy(
                $row->type ?? null,
                $row->no_of_hours ?? null,
                $this->calendarDate($row->original_work_day ?? null),
                $this->calendarDate($row->offset_work_day ?? null),
            );
            $id = (string) ($row->id ?? '');
            $category = trim((string) ($row->category ?? ''));
            $reason = trim((string) ($row->reason ?? ''));

            if ($kind === PortalSubmittedReportPresenter::OCCUPANCY_LEAVE) {
                $date = $this->calendarDate($row->request_date ?? null);
                if ($date === null || ! $this->inRange($date, $from, $to)) {
                    continue;
                }
                $leaveDays[] = [
                    'id' => $id,
                    'who' => $who,
                    'date' => $date,
                    'detail' => $category !== '' ? $category : ($reason !== '' ? $reason : null),
                    'label' => $this->leaveTypeLabel($category, $reason),
                ];

                continue;
            }

            if ($kind === PortalSubmittedReportPresenter::OCCUPANCY_OVERTIME) {
                $date = $this->calendarDate($row->request_date ?? null);
                if ($date === null || ! $this->inRange($date, $from, $to)) {
                    continue;
                }
                $data[] = PortalCalendarEventPresenter::event(
                    'overtime-'.$id,
                    $who.' overtime',
                    $date,
                    'event',
                    null,
                    $reason !== '' ? $reason : 'Overtime',
                );
                $requestCount++;

                continue;
            }

            if ($kind === PortalSubmittedReportPresenter::OCCUPANCY_OFFSET) {
                $work = $this->calendarDate($row->original_work_day ?? null)
                    ?? $this->calendarDate($row->request_date ?? null);
                $off = $this->calendarDate($row->offset_work_day ?? null);
                if ($work !== null && $this->inRange($work, $from, $to)) {
                    $data[] = PortalCalendarEventPresenter::event(
                        'offset-work-'.$id,
                        $who.' offset work',
                        $work,
                        'event',
                        null,
                        $reason !== '' ? $reason : 'Offset work day',
                    );
                    $requestCount++;
                }
                if ($off !== null && $this->inRange($off, $from, $to)) {
                    $data[] = PortalCalendarEventPresenter::event(
                        'offset-off-'.$id,
                        $who.' offset day off',
                        $off,
                        'event',
                        null,
                        $reason !== '' ? $reason : 'Offset day off',
                    );
                    $requestCount++;
                }

                continue;
            }

            $date = $this->calendarDate($row->request_date ?? null);
            if ($date === null || ! $this->inRange($date, $from, $to)) {
                continue;
            }
            $data[] = PortalCalendarEventPresenter::event(
                'request-'.$id,
                $who.' request',
                $date,
                'event',
                null,
                $reason !== '' ? $reason : (trim((string) ($row->type ?? '')) !== '' ? trim((string) $row->type) : null),
            );
            $requestCount++;
        }

        foreach ($this->collapseLeave($leaveDays) as $leave) {
            $data[] = PortalCalendarEventPresenter::event(
                $leave['id'],
                $leave['title'],
                $leave['startsOn'],
                'leave',
                null,
                $leave['detail'],
                $leave['endsOn'],
            );
            $leaveCount++;
            $requestCount++;
        }

        foreach ($this->reimbursementRows($from, $to) as $row) {
            if (PortalSubmittedReportPresenter::isCancelled($row->status ?? null)
                || PortalSubmittedReportPresenter::isRefused($row->status ?? null)) {
                continue;
            }
            $date = $this->calendarDate($row->reimb_date ?? null);
            if ($date === null || ! $this->inRange($date, $from, $to)) {
                continue;
            }
            $name = trim((string) ($row->employee_name_input ?? ''));
            $who = $name !== '' ? $name : 'Member';
            $item = trim((string) ($row->item ?? ''));
            $data[] = PortalCalendarEventPresenter::event(
                'reimburse-'.(string) ($row->id ?? ''),
                $who.' reimbursement',
                $date,
                'event',
                null,
                $item !== '' ? $item : 'Reimbursement',
            );
            $requestCount++;
        }

        foreach ($this->customEventRows($actor, $from, $to) as $event) {
            $data[] = $event;
        }

        usort($data, function (array $left, array $right): int {
            $byDate = strcmp((string) $left['startsOn'], (string) $right['startsOn']);
            if ($byDate !== 0) {
                return $byDate;
            }

            return strcmp((string) $left['id'], (string) $right['id']);
        });

        return [
            'section' => 'calendar',
            'resource' => 'events',
            'data' => array_values($data),
            'counts' => [
                'events' => count($data),
                'holidays' => $holidayCount,
                'leave' => $leaveCount,
                'requests' => $requestCount,
            ],
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Consecutive leave days for the same member and type become one event, so the grid can draw
     * a single bar across those days instead of a chip on each cell.
     *
     * @param  list<array{id: string, who: string, date: string, detail: string|null, label: string}>  $days
     * @return list<array{id: string, title: string, startsOn: string, endsOn: string|null, detail: string|null}>
     */
    private function collapseLeave(array $days): array
    {
        $groups = [];
        foreach ($days as $day) {
            $key = strtolower($day['who']).'|'.strtolower((string) $day['detail']).'|'.strtolower($day['label']);
            $groups[$key][] = $day;
        }

        $runs = [];
        foreach ($groups as $group) {
            usort($group, fn (array $left, array $right): int => strcmp($left['date'], $right['date']));
            $run = null;
            foreach ($group as $day) {
                if ($run !== null && $this->nextDay($run['endsOn'] ?? $run['startsOn']) === $day['date']) {
                    $run['endsOn'] = $day['date'];

                    continue;
                }
                if ($run !== null) {
                    $runs[] = $run;
                }
                $run = [
                    'id' => 'leave-'.$day['id'],
                    'title' => $day['who'].': '.$day['label'],
                    'startsOn' => $day['date'],
                    'endsOn' => null,
                    'detail' => $day['detail'],
                ];
            }
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    private function nextDay(string $date): string
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $date);
        if (! $parsed instanceof Carbon) {
            return $date;
        }

        return $parsed->addDay()->toDateString();
    }

    private function leaveTypeLabel(string $category, string $reason): string
    {
        if ($category !== '') {
            $stripped = trim((string) preg_replace('/^\d+\s+/', '', $category));

            return $stripped !== '' ? $stripped : $category;
        }

        return $reason !== '' ? $reason : 'Leave';
    }

    /**
     * @return list<object>
     */
    private function requestRows(string $from, string $to): array
    {
        return LeaveRequest::query()
            ->toBase()
            ->select([
                'id',
                'name',
                'type',
                'category',
                'status',
                'reason',
                'no_of_hours',
                'request_date',
                'original_work_day',
                'offset_work_day',
            ])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('request_date', [$from, $to])
                    ->orWhereBetween('original_work_day', [$from, $to])
                    ->orWhereBetween('offset_work_day', [$from, $to]);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return list<object>
     */
    private function reimbursementRows(string $from, string $to): array
    {
        return Reimbursement::query()
            ->toBase()
            ->select([
                'id',
                'employee_name_input',
                'reimb_date',
                'item',
                'status',
            ])
            ->whereBetween('reimb_date', [$from, $to])
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function inRange(string $date, string $from, string $to): bool
    {
        return $date >= $from && $date <= $to;
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

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $title = $this->validatedTitle($request);
        $details = $this->validatedDetails($request);
        [$startsOn, $endsOn] = $this->validatedDates($request);
        $startsAt = $this->validatedTime($request, 'startsAt');
        $endsAt = $this->validatedTime($request, 'endsAt');
        $this->assertTimesAreOrdered($startsOn, $endsOn, $startsAt, $endsAt);
        $category = $this->validatedCategory($request);
        $audience = $this->validatedAudience($request);
        $departments = $audience === self::AUDIENCE_DEPARTMENT
            ? $this->validatedDepartments($request)
            : [];
        $memberIds = $audience === self::AUDIENCE_MEMBERS
            ? $this->validatedMemberIds($request)
            : [];

        $createdAt = $this->timezone->now();
        $employeeId = (int) $actor->getKey();

        $id = (int) $this->connection()->transaction(function () use (
            $title,
            $details,
            $startsOn,
            $startsAt,
            $endsOn,
            $endsAt,
            $category,
            $audience,
            $departments,
            $memberIds,
            $createdAt,
            $employeeId,
        ): int {
            $eventId = (int) $this->connection()->table('calendar_events')->insertGetId([
                'title' => $title,
                'details' => $details,
                'starts_on' => $startsOn,
                'starts_at' => $startsAt,
                'ends_on' => $endsOn === $startsOn ? null : $endsOn,
                'ends_at' => $endsAt,
                'category' => $category,
                'audience' => $audience,
                'created_by' => $employeeId,
                'date_created' => $createdAt->toDateTimeString(),
            ]);

            foreach ($departments as $department) {
                $this->connection()->table('calendar_event_departments')->insert([
                    'event_id' => $eventId,
                    'department' => $department,
                ]);
            }
            foreach ($memberIds as $memberId) {
                $this->connection()->table('calendar_event_members')->insert([
                    'event_id' => $eventId,
                    'employee_id' => $memberId,
                ]);
            }

            return $eventId;
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            self::CORE_RESOURCE,
            PortalActivityCopy::createdEvent($title, $startsOn, $endsOn),
            (string) $id,
            $request,
            [
                'title' => $title,
                'startsOn' => $startsOn,
                'startsAt' => $startsAt,
                'endsOn' => $endsOn,
                'endsAt' => $endsAt,
                'category' => $category,
                'audience' => $audience,
                'departments' => $departments,
                'memberIds' => $memberIds,
            ],
        );
        $this->bumpCache();

        return [
            'section' => 'calendar',
            'resource' => 'events',
            'data' => PortalCalendarEventPresenter::event(
                'calendar-'.$id,
                $title,
                $startsOn,
                $this->kindForCategory($category),
                $startsAt,
                $details,
                $endsOn,
            ),
        ];
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array{
     *         departments: list<array{value: string, label: string}>,
     *         members: list<array{id: int, name: string}>
     *     }
     * }
     */
    public function options(): array
    {
        $version = (int) Cache::get(self::OPTIONS_CACHE_VERSION_KEY, 1);
        $key = 'portal:calendar:event-options:'.$version;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function (): array {
            return $this->buildOptions();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customEventRows(Employee $actor, string $from, string $to): array
    {
        if (! Schema::connection('portal')->hasTable('calendar_events')) {
            return [];
        }

        $rows = $this->connection()
            ->table('calendar_events')
            ->select([
                'id',
                'title',
                'details',
                'starts_on',
                'starts_at',
                'ends_on',
                'category',
                'audience',
                'created_by',
            ])
            ->where('starts_on', '<=', $to)
            ->whereRaw('COALESCE(ends_on, starts_on) >= ?', [$from])
            ->orderBy('id')
            ->get()
            ->all();

        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        $departmentsByEvent = $this->departmentsByEvent($ids);
        $membersByEvent = $this->membersByEvent($ids);
        $events = [];

        foreach ($rows as $row) {
            if (! $this->canSee($actor, $row, $departmentsByEvent, $membersByEvent)) {
                continue;
            }
            $startsOn = $this->calendarDate($row->starts_on ?? null);
            if ($startsOn === null) {
                continue;
            }
            $endsOn = $this->calendarDate($row->ends_on ?? null);
            $category = trim((string) ($row->category ?? ''));
            $details = trim((string) ($row->details ?? ''));
            $events[] = PortalCalendarEventPresenter::event(
                'calendar-'.(string) ((int) $row->id),
                is_string($row->title ?? null) ? $row->title : 'Event',
                $startsOn,
                $this->kindForCategory($category),
                $this->clockTime($row->starts_at ?? null),
                $details !== '' ? $details : null,
                $endsOn,
            );
        }

        return $events;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, list<string>>
     */
    private function departmentsByEvent(array $eventIds): array
    {
        if ($eventIds === [] || ! Schema::connection('portal')->hasTable('calendar_event_departments')) {
            return [];
        }

        $grouped = [];
        $rows = $this->connection()
            ->table('calendar_event_departments')
            ->select(['event_id', 'department'])
            ->whereIn('event_id', $eventIds)
            ->get();
        foreach ($rows as $row) {
            $eventId = (int) $row->event_id;
            $department = trim((string) ($row->department ?? ''));
            if ($department === '') {
                continue;
            }
            $grouped[$eventId][] = $department;
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, list<int>>
     */
    private function membersByEvent(array $eventIds): array
    {
        if ($eventIds === [] || ! Schema::connection('portal')->hasTable('calendar_event_members')) {
            return [];
        }

        $grouped = [];
        $rows = $this->connection()
            ->table('calendar_event_members')
            ->select(['event_id', 'employee_id'])
            ->whereIn('event_id', $eventIds)
            ->get();
        foreach ($rows as $row) {
            $grouped[(int) $row->event_id][] = (int) $row->employee_id;
        }

        return $grouped;
    }

    /**
     * @param  object{id?: mixed, audience?: mixed, created_by?: mixed}  $row
     * @param  array<int, list<string>>  $departmentsByEvent
     * @param  array<int, list<int>>  $membersByEvent
     */
    private function canSee(
        Employee $actor,
        object $row,
        array $departmentsByEvent,
        array $membersByEvent,
    ): bool {
        $actorId = (int) $actor->getKey();
        if ((int) ($row->created_by ?? 0) === $actorId) {
            return true;
        }

        $audience = trim((string) ($row->audience ?? ''));
        if ($audience === self::AUDIENCE_EVERYONE) {
            return true;
        }

        $eventId = (int) ($row->id ?? 0);
        if ($audience === self::AUDIENCE_DEPARTMENT) {
            $department = trim((string) ($actor->department ?? ''));
            if ($department === '') {
                return false;
            }
            foreach ($departmentsByEvent[$eventId] ?? [] as $assigned) {
                if (strcasecmp($assigned, $department) === 0) {
                    return true;
                }
            }

            return false;
        }

        if ($audience === self::AUDIENCE_MEMBERS) {
            return in_array($actorId, $membersByEvent[$eventId] ?? [], true);
        }

        return false;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array{
     *         departments: list<array{value: string, label: string}>,
     *         members: list<array{id: int, name: string}>
     *     }
     * }
     */
    private function buildOptions(): array
    {
        $rows = Employee::query()
            ->toBase()
            ->select(['id', 'first_name', 'last_name', 'department'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        $departments = [];
        $members = [];
        foreach ($rows as $row) {
            $department = trim((string) ($row->department ?? ''));
            if ($department !== '') {
                $departments[strtolower($department)] = $department;
            }
            $name = PortalSubmittedReportPresenter::memberName(
                is_string($row->first_name ?? null) ? $row->first_name : null,
                is_string($row->last_name ?? null) ? $row->last_name : null,
            );
            if ($name === '') {
                continue;
            }
            $members[] = [
                'id' => (int) $row->id,
                'name' => $name,
            ];
        }
        ksort($departments);
        $departmentOptions = [];
        foreach ($departments as $department) {
            $departmentOptions[] = ['value' => $department, 'label' => $department];
        }

        return [
            'section' => 'calendar',
            'resource' => 'event-options',
            'data' => [
                'departments' => $departmentOptions,
                'members' => $members,
            ],
        ];
    }

    private function validatedTitle(Request $request): string
    {
        $title = trim((string) $request->input('title', ''));
        if ($title === '') {
            abort(422, 'An event needs a title.');
        }
        if (strlen($title) > self::MAX_TITLE) {
            abort(422, 'The title cannot exceed 255 characters.');
        }

        return $title;
    }

    private function validatedDetails(Request $request): ?string
    {
        $details = trim((string) $request->input('details', ''));
        if ($details === '') {
            return null;
        }
        if (strlen($details) > self::MAX_DETAILS) {
            abort(422, 'The details cannot exceed 2000 characters.');
        }

        return $details;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function validatedDates(Request $request): array
    {
        $start = $this->parseDate(trim((string) $request->input('startsOn', '')));
        $endRaw = trim((string) $request->input('endsOn', ''));
        $end = $endRaw === '' ? $start : $this->parseDate($endRaw);
        if ($end->lt($start)) {
            abort(422, 'The end date cannot come before the start date.');
        }
        if ($start->diffInDays($end) > self::MAX_SPAN_DAYS) {
            abort(422, 'One event cannot cover more than a year.');
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }
        $parsed = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
        if (! $parsed instanceof Carbon) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $parsed->startOfDay();
    }

    private function validatedTime(Request $request, string $key): ?string
    {
        $raw = trim((string) $request->input($key, ''));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $raw, $matches) !== 1) {
            abort(422, 'Times must use HH:mm.');
        }

        return $matches[1].':'.$matches[2];
    }

    private function assertTimesAreOrdered(string $startsOn, string $endsOn, ?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt === null || $endsAt === null || $startsOn !== $endsOn) {
            return;
        }
        if (strcmp($endsAt, $startsAt) < 0) {
            abort(422, 'The end time cannot come before the start time.');
        }
    }

    private function validatedCategory(Request $request): string
    {
        $category = trim((string) $request->input('category', ''));
        if (! in_array($category, self::CATEGORIES, true)) {
            abort(422, 'Choose a category the form offers.');
        }

        return $category;
    }

    private function validatedAudience(Request $request): string
    {
        $audience = trim((string) $request->input('audience', ''));
        if (! in_array($audience, self::AUDIENCES, true)) {
            abort(422, 'Choose who the event is for.');
        }

        return $audience;
    }

    /**
     * @return list<string>
     */
    private function validatedDepartments(Request $request): array
    {
        $raw = $request->input('departments', []);
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Choose at least one department.');
        }
        $allowed = [];
        foreach ($this->activeDepartments() as $department) {
            $allowed[strtolower($department)] = $department;
        }
        $chosen = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                abort(422, 'Choose departments from the list the form offers.');
            }
            $trimmed = trim($entry);
            $match = $allowed[strtolower($trimmed)] ?? null;
            if ($match === null) {
                abort(422, 'Choose departments from the list the form offers.');
            }
            $chosen[strtolower($match)] = $match;
        }
        if ($chosen === []) {
            abort(422, 'Choose at least one department.');
        }

        return array_values($chosen);
    }

    /**
     * @return list<int>
     */
    private function validatedMemberIds(Request $request): array
    {
        $raw = $request->input('memberIds', []);
        if (! is_array($raw) || $raw === []) {
            abort(422, 'Choose at least one member.');
        }
        $ids = [];
        foreach ($raw as $entry) {
            if (is_int($entry) && $entry > 0) {
                $ids[$entry] = $entry;

                continue;
            }
            if (is_string($entry) && preg_match('/^\d+$/', $entry) === 1) {
                $id = (int) $entry;
                if ($id > 0) {
                    $ids[$id] = $id;
                }

                continue;
            }
            abort(422, 'Choose members from the list the form offers.');
        }
        if ($ids === []) {
            abort(422, 'Choose at least one member.');
        }
        $active = Employee::query()
            ->toBase()
            ->select(['id'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereIn('id', array_values($ids))
            ->pluck('id')
            ->all();
        $activeIds = [];
        foreach ($active as $id) {
            $activeIds[(int) $id] = (int) $id;
        }
        foreach ($ids as $id) {
            if (! isset($activeIds[$id])) {
                abort(422, 'Choose members from the list the form offers.');
            }
        }

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function activeDepartments(): array
    {
        $rows = Employee::query()
            ->toBase()
            ->select(['department'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department');
        $departments = [];
        foreach ($rows as $department) {
            $trimmed = trim((string) $department);
            if ($trimmed !== '') {
                $departments[] = $trimmed;
            }
        }

        return $departments;
    }

    private function kindForCategory(string $category): string
    {
        return $category === self::CATEGORY_PROJECT ? 'project' : 'event';
    }

    private function clockTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('H:i');
        }
        if (is_string($value) && preg_match('/^(\d{2}):(\d{2})/', $value, $matches) === 1) {
            return $matches[1].':'.$matches[2];
        }

        return null;
    }

    private function connection(): Connection
    {
        return Employee::query()->getConnection();
    }
}
