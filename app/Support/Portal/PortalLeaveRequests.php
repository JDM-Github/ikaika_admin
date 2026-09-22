<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\UserReport;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Requests / Leave: the days the signed-in member is asking to be away.
 *
 * The requests table holds one day per row -- a five-day leave in the seeded data is five rows
 * under one reason -- so the form's start and end dates are a range to expand, not a pair to
 * store. The table has no employee junction; Airtable stored the member as `name`, so ownership
 * is the same composed name the occupancy reads already match on.
 */
final class PortalLeaveRequests
{
    public const CACHE_TTL_SECONDS = 30;

    public const TYPE = 'Leave';

    // Same read window as the timesheet history, so the two screens reach back equally far.
    private const MAX_RANGE_MONTHS = 24;

    // Leave is booked as well as recorded, so the picker reaches forward where reports do not.
    private const MAX_AHEAD_MONTHS = 12;

    private const MAX_DAYS = 31;

    private const MAX_REASON = 500;

    private const NEW_STATUS = 'Pending';

    private const CANCELLED_STATUS = 'Cancelled';

    private const CACHE_VERSION_KEY = 'portal:requests:leave:version';

    private const CORE_TARGET = 'portal.requests';

    private const CORE_RESOURCE = 'requests.leave';

    /**
     * The company's leave vocabulary. The form offers exactly this list, so an unknown type is a
     * client that has drifted rather than a new kind of leave -- move both together.
     *
     * @var list<string>
     */
    private const TYPES = [
        '01 Vacation Leave',
        '02 Sick Leave',
        '03 Emergency Leave',
        '04 Bereavement Leave',
        '05 Maternity or Paternity Leave',
    ];

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalSubmittedReports $reports,
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
        private readonly PortalCalendarEvents $calendar,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     types: list<string>,
     *     reportedDays: list<string>,
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:requests:leave:'.$version.':'.$actor->getKey().':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $from, $to): array {
            return [
                'section' => 'requests',
                'resource' => 'leave',
                'data' => $this->ownLeave($actor, $from, $to),
                'types' => self::TYPES,
                // The form closes a day the member already reported: they worked it, so there is
                // nothing to be away from. Sent with the history so the screen asks once.
                'reportedDays' => $this->reportedDays((int) $actor->getKey(), $from, $to),
                'range' => ['from' => $from, 'to' => $to],
            ];
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
        $this->calendar->bumpCache();
    }

    /**
     * File leave over a range of days. One row per day, all in one transaction, so a five-day
     * application cannot land as three days and a failure.
     *
     * @return array{section: string, resource: string, data: list<array<string, mixed>>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $type = $this->validatedType($request);
        $reason = $this->validatedReason($request);
        $dates = $this->validatedRange($request);
        $employeeId = (int) $actor->getKey();
        $name = $this->memberName($actor);

        $this->assertDatesAreFree($actor, $dates);
        $this->assertDatesAreNotReported($employeeId, $dates);

        $createdAt = $this->timezone->now();

        $insertedIds = $this->connection()->transaction(function () use (
            $dates,
            $name,
            $type,
            $reason,
            $createdAt,
        ): array {
            $ids = [];
            foreach ($dates as $date) {
                $ids[] = (int) $this->connection()->table('requests')->insertGetId([
                    'request_date' => $date,
                    'name' => $name,
                    'reason' => $reason,
                    'status' => self::NEW_STATUS,
                    'type' => self::TYPE,
                    'category' => $type,
                    'date_created' => $createdAt->toDateTimeString(),
                ]);
            }

            return $ids;
        });

        try {
            foreach ($dates as $index => $date) {
                $id = (string) $insertedIds[$index];
                $this->ledger->recordAdd(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreRecycleKey::leaveRequest($employeeId, $date),
                    self::CORE_TARGET,
                    [
                        'id' => $id,
                        'requestedFor' => $date,
                        'leaveType' => $type,
                        'reason' => $reason,
                    ],
                    self::CORE_RESOURCE,
                    $id,
                    $employeeId,
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
            }
        } catch (Throwable $error) {
            $this->deleteRequests($insertedIds);
            throw $error;
        }

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            self::CORE_RESOURCE,
            PortalActivityCopy::filedLeave($type, $dates[0] ?? '', $dates[array_key_last($dates)] ?? ''),
            (string) ($insertedIds[0] ?? ''),
            $request,
            [
                'leaveType' => $type,
                'requestedFor' => $dates,
                'reason' => $reason,
            ],
        );
        $this->audit->notifyManagers(
            $actor,
            PortalNotificationType::REQUEST_FILED_LEAVE,
            'New leave request',
            PortalActivityCopy::notifyFiled(
                PortalActivityCopy::displayName($actor),
                'leave request',
                $dates[0] ?? '',
                $dates[array_key_last($dates)] ?? '',
            ),
            PortalShellPath::MANAGE_REQUESTS,
            'Open queue',
            ['recordId' => (string) ($insertedIds[0] ?? '')],
        );

        $this->bumpCache();
        // Leave closes a day to the report forms, which read it off the submitted-days payload.
        $this->reports->bumpCache();

        return [
            'section' => 'requests',
            'resource' => 'leave',
            'data' => $this->read($insertedIds, $createdAt->toDateString(), $type),
        ];
    }

    /**
     * Change a leave day that nobody has decided on yet. Only the type, the reason and the day
     * itself: a decided request is the approver's record and is not this form's to rewrite.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function replace(Employee $actor, string $id, Request $request): array
    {
        $row = $this->ownPendingRow($actor, $id);
        $current = $this->calendarDate($row->request_date ?? null);
        $type = $this->validatedType($request);
        $reason = $this->validatedReason($request);
        $date = $this->parseDate(trim((string) $request->input('requestDate', '')));
        $this->assertFilableDate($date, $current);
        $day = $date->toDateString();

        // Its own day is not a clash with itself; anything else is checked the way filing is.
        if ($day !== $current) {
            $this->assertDatesAreFree($actor, [$day]);
            $this->assertDatesAreNotReported((int) $actor->getKey(), [$day]);
        }

        $this->connection()->table('requests')->where('id', (int) $row->id)->update([
            'request_date' => $day,
            'reason' => $reason,
            'category' => $type,
        ]);

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => (string) (int) $row->id,
                'requestedFor' => $day,
                'leaveType' => $type,
                'reason' => $reason,
            ],
            self::CORE_RESOURCE,
            (string) (int) $row->id,
            CoreRecycleKey::leaveRequest((int) $actor->getKey(), $day),
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::updatedLeave($type, $day),
            (string) (int) $row->id,
            $request,
            [
                'leaveType' => $type,
                'requestedFor' => $day,
                'reason' => $reason,
            ],
        );

        $this->bumpCache();
        $this->reports->bumpCache();

        return [
            'section' => 'requests',
            'resource' => 'leave',
            'data' => $this->one((int) $row->id),
        ];
    }

    /**
     * Withdraw a leave day. The row stays and its status changes: the history is a record of what
     * was asked for, and a cancelled request that vanished would read as one never filed.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function cancel(Employee $actor, string $id, Request $request): array
    {
        $row = $this->ownPendingRow($actor, $id);
        $day = $this->calendarDate($row->request_date ?? null) ?? '';

        $this->connection()->table('requests')
            ->where('id', (int) $row->id)
            ->update(['status' => self::CANCELLED_STATUS]);

        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            self::CORE_TARGET,
            [
                'id' => (string) (int) $row->id,
                'requestedFor' => $day,
                'status' => self::CANCELLED_STATUS,
            ],
            self::CORE_RESOURCE,
            (string) (int) $row->id,
            CoreRecycleKey::leaveRequest((int) $actor->getKey(), $day),
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::cancelledLeave(
                is_string($row->category ?? null) ? $row->category : 'leave',
                $day,
            ),
            (string) (int) $row->id,
            $request,
            [
                'leaveType' => is_string($row->category ?? null) ? $row->category : 'leave',
                'requestedFor' => $day,
                'status' => self::CANCELLED_STATUS,
            ],
        );
        $this->audit->notifyManagers(
            $actor,
            PortalNotificationType::REQUEST_CANCELLED_LEAVE,
            'Leave request cancelled',
            PortalActivityCopy::notifyCancelled(PortalActivityCopy::displayName($actor), 'leave request', $day),
            PortalShellPath::MANAGE_REQUESTS,
            'Open queue',
            ['recordId' => (string) (int) $row->id],
        );

        // A cancelled day is free again, for this form and the report forms both.
        $this->bumpCache();
        $this->reports->bumpCache();

        return [
            'section' => 'requests',
            'resource' => 'leave',
            'data' => $this->one((int) $row->id),
        ];
    }

    /**
     * The member's own leave row, still undecided. Somebody else's is a 404 rather than a 403:
     * whose request it is is not this member's to learn.
     */
    private function ownPendingRow(Employee $actor, string $id): object
    {
        if (preg_match('/^\d{1,11}$/', trim($id)) !== 1) {
            abort(404, 'That request was not found.');
        }

        $name = $this->memberName($actor);
        $row = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'status',
                'type',
                'category',
                'reason',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->where('id', (int) $id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->first();

        if ($row === null) {
            abort(404, 'That request was not found.');
        }

        $kind = PortalSubmittedReportPresenter::occupancy(
            $row->type ?? null,
            $row->no_of_hours ?? null,
            $this->calendarDate($row->original_work_day ?? null),
            $this->calendarDate($row->offset_work_day ?? null),
        );
        if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_LEAVE) {
            abort(404, 'That request was not found.');
        }

        if (PortalSubmittedReportPresenter::requestStatus($row->status ?? null) !== 'pending') {
            abort(422, 'Only a request nobody has decided on yet can be changed.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function one(int $id): array
    {
        $row = $this->connection()
            ->table('requests')
            ->select(['id', 'request_date', 'date_created', 'reason', 'status', 'approver_remarks', 'category'])
            ->where('id', $id)
            ->first();

        if ($row === null) {
            abort(500, 'The request was changed but could not be read back.');
        }

        $requestedOn = $this->calendarDate($row->request_date ?? null) ?? '';

        return [
            'id' => (string) (int) $row->id,
            'createdOn' => $this->calendarDate($row->date_created ?? null) ?? $requestedOn,
            'requestedOn' => $requestedOn,
            'leaveTypeLabel' => $this->text($row->category ?? null) ?? 'Leave',
            'remarks' => $this->text($row->reason ?? null),
            'status' => PortalSubmittedReportPresenter::requestStatus($row->status ?? null),
            'approverRemarks' => $this->text($row->approver_remarks ?? null),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function read(array $ids, string $createdOn, string $type): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('requests')
            ->select(['id', 'request_date', 'reason', 'status'])
            ->whereIn('id', $ids)
            ->orderBy('request_date')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => (string) (int) $row->id,
                'createdOn' => $createdOn,
                'requestedOn' => $this->calendarDate($row->request_date ?? null) ?? $createdOn,
                'leaveTypeLabel' => $type,
                'remarks' => $this->text($row->reason ?? null),
                'status' => PortalSubmittedReportPresenter::requestStatus($row->status ?? null),
                'approverRemarks' => null,
            ];
        }

        return $data;
    }

    /**
     * The member's own leave in the window, newest first. Rows are classified by the same
     * occupancy rule the report calendar uses, so a legacy untyped row with hours on it stays
     * what it is -- extra time worked, not a day away.
     *
     * @return list<array<string, mixed>>
     */
    private function ownLeave(Employee $actor, string $from, string $to): array
    {
        $name = $this->composedName($actor);
        if ($name === null) {
            return [];
        }

        $rows = $this->connection()
            ->table('requests')
            ->select([
                'id',
                'request_date',
                'date_created',
                'reason',
                'status',
                'approver_remarks',
                'category',
                'type',
                'no_of_hours',
                'original_work_day',
                'offset_work_day',
            ])
            ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
            ->whereNotNull('request_date')
            ->whereBetween('request_date', [$from, $to])
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $kind = PortalSubmittedReportPresenter::occupancy(
                $row->type ?? null,
                $row->no_of_hours ?? null,
                $this->calendarDate($row->original_work_day ?? null),
                $this->calendarDate($row->offset_work_day ?? null),
            );
            if ($kind !== PortalSubmittedReportPresenter::OCCUPANCY_LEAVE) {
                continue;
            }
            $requestedOn = $this->calendarDate($row->request_date ?? null);
            if ($requestedOn === null) {
                continue;
            }
            $data[] = [
                'id' => (string) (int) $row->id,
                // Older rows carry no created stamp; the day itself is the honest fallback.
                'createdOn' => $this->calendarDate($row->date_created ?? null) ?? $requestedOn,
                'requestedOn' => $requestedOn,
                'leaveTypeLabel' => $this->text($row->category ?? null) ?? 'Leave',
                'remarks' => $this->text($row->reason ?? null),
                'status' => PortalSubmittedReportPresenter::requestStatus($row->status ?? null),
                'approverRemarks' => $this->text($row->approver_remarks ?? null),
            ];
        }

        return $data;
    }

    /**
     * One grouped read over (employee_id, report_date), the same shape the submitted-days list
     * uses. No label joins: the picker only asks whether the day is spoken for.
     *
     * @return list<string>
     */
    private function reportedDays(int $employeeId, string $from, string $to): array
    {
        $rows = UserReport::query()
            ->toBase()
            ->select(['user_reports.report_date'])
            ->join('employees_user_reports', 'employees_user_reports.user_report_id', '=', 'user_reports.id')
            ->where('employees_user_reports.employee_id', $employeeId)
            ->whereNotNull('user_reports.report_date')
            ->whereBetween('user_reports.report_date', [$from, $to])
            ->groupBy('user_reports.report_date')
            ->orderBy('user_reports.report_date')
            ->get();

        $days = [];
        foreach ($rows as $row) {
            $date = $this->calendarDate($row->report_date ?? null);
            if ($date !== null) {
                $days[] = $date;
            }
        }

        return PortalSubmittedReportPresenter::uniqueDates($days);
    }

    /**
     * @param  list<string>  $dates
     */
    private function assertDatesAreFree(Employee $actor, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $taken = $this->reports->leaveDays($actor, min($dates), max($dates));
        foreach ($dates as $date) {
            if (in_array($date, $taken, true)) {
                abort(422, 'You already have leave filed for '.$date.'.');
            }
        }
    }

    /**
     * The mirror of the rule the report forms follow. A day with a report on it was worked, so
     * asking to be away from it contradicts the record rather than adding to it.
     *
     * @param  list<string>  $dates
     */
    private function assertDatesAreNotReported(int $employeeId, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $reported = $this->reportedDays($employeeId, min($dates), max($dates));
        foreach ($dates as $date) {
            if (in_array($date, $reported, true)) {
                abort(422, 'You already filed a report for '.$date.', so that day was worked.');
            }
        }
    }

    private function validatedType(Request $request): string
    {
        $value = trim((string) $request->input('leaveType', ''));
        if (! in_array($value, self::TYPES, true)) {
            abort(422, 'Choose one of the leave types the form offers.');
        }

        return $value;
    }

    private function validatedReason(Request $request): string
    {
        $value = $request->input('reason');
        if (! is_scalar($value)) {
            abort(422, 'Leave needs a reason.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            abort(422, 'Leave needs a reason.');
        }
        if (strlen($text) > self::MAX_REASON) {
            abort(422, 'The reason cannot exceed 500 characters.');
        }

        return $text;
    }

    /**
     * Every day the application covers, both ends included -- one day off is a range of one.
     *
     * @return list<string>
     */
    private function validatedRange(Request $request): array
    {
        $start = $this->parseDate(trim((string) $request->input('startDate', '')));
        $end = $this->parseDate(trim((string) $request->input('endDate', '')));
        if ($end->lt($start)) {
            abort(422, 'The end date cannot come before the start date.');
        }
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            abort(422, 'One application cannot cover more than 31 days.');
        }

        $this->assertFilableDate($start);
        $this->assertFilableDate($end);

        $dates = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * Leave is asked for, not recorded after the fact: the day has to be today or later. A day
     * already gone is either a report or an absence somebody else has to sort out, and neither is
     * this form's to file. `$floor` lets an edit keep the day it already had.
     */
    private function assertFilableDate(Carbon $day, ?string $floor = null): void
    {
        $today = $this->timezone->today();
        $earliest = $floor === null ? $today : $this->timezone->day($floor)->min($today);
        if ($day->lt($earliest)) {
            abort(422, 'Leave starts today or later.');
        }
        if ($day->gt($today->copy()->addMonths(self::MAX_AHEAD_MONTHS))) {
            abort(422, 'Leave cannot be booked more than a year ahead.');
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $to = $toRaw === ''
            ? $this->timezone->today()->addMonths(self::MAX_AHEAD_MONTHS)
            : $this->parseDate($toRaw);
        $floor = $to->copy()->subMonths(self::MAX_RANGE_MONTHS + self::MAX_AHEAD_MONTHS)->startOfMonth();
        $from = $fromRaw === '' ? $floor->copy() : $this->parseDate($fromRaw);

        if ($from->gt($to)) {
            abort(422, 'The from date must be on or before the to date.');
        }
        if ($from->lt($floor)) {
            $from = $floor;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value, $this->timezone->zone());
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->startOfDay();
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

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function composedName(Employee $actor): ?string
    {
        $name = PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );

        return $name === 'Member' ? null : $name;
    }

    private function memberName(Employee $actor): string
    {
        $name = $this->composedName($actor);
        if ($name === null) {
            abort(422, 'Your profile has no name on it, so a request cannot be filed under it.');
        }

        return $name;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deleteRequests(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->connection()->table('requests')->whereIn('id', $ids)->delete();
    }

    private function connection(): Connection
    {
        return UserReport::query()->getConnection();
    }
}
