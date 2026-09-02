<?php

namespace App\Support\Portal;

/**
 * Skinny Submitted Reports payload. Approval, bank, and identity fields never leave this class.
 */
final class PortalSubmittedReportPresenter
{
    public const KIND_DAILY = 'daily';

    public const KIND_LATE = 'late';

    public const OCCUPANCY_LEAVE = 'leave';

    public const OCCUPANCY_OFFSET = 'offset';

    public const OCCUPANCY_OVERTIME = 'overtime';

    private const UNASSIGNED = 'Unassigned';

    /**
     * Empty, No, and On Time stay daily. Any other late_submission value is a late filing.
     */
    public static function kind(mixed $lateSubmission): string
    {
        $value = strtolower(trim((string) $lateSubmission));
        if ($value === '' || in_array($value, ['no', 'n', 'false', '0', 'on time', 'ontime', 'daily'], true)) {
            return self::KIND_DAILY;
        }

        return self::KIND_LATE;
    }

    public static function memberName(?string $firstName, ?string $lastName): string
    {
        $name = trim(trim((string) $firstName).' '.trim((string) $lastName));

        return $name !== '' ? $name : 'Member';
    }

    public static function referenceCode(mixed $idNo, mixed $employeeId): string
    {
        $code = trim((string) $idNo);

        return $code !== '' ? $code : (string) $employeeId;
    }

    public static function reason(mixed $remarks): ?string
    {
        $value = trim((string) $remarks);

        return $value !== '' ? $value : null;
    }

    public static function projectLabel(mixed $number, mixed $name): string
    {
        $label = trim(trim((string) $number).' '.trim((string) $name));

        return $label !== '' ? $label : self::UNASSIGNED;
    }

    public static function activityLabel(mixed $name, mixed $idNo): string
    {
        $named = trim((string) $name);
        if ($named !== '') {
            return $named;
        }
        $code = trim((string) $idNo);

        return $code !== '' ? $code : self::UNASSIGNED;
    }

    public static function earnCodeLabel(mixed $description): string
    {
        $value = trim((string) $description);

        return $value !== '' ? $value : self::UNASSIGNED;
    }

    /**
     * @param  list<string>  $projectLabels
     * @param  list<string>  $activityLabels
     * @param  list<string>  $earnCodeLabels
     * @return array{
     *     id: string,
     *     projectLabel: string,
     *     activityLabel: string,
     *     earnCodeLabel: string,
     *     hoursRendered: float,
     *     elementChange: float
     * }
     */
    public static function entry(
        string $id,
        array $projectLabels,
        array $activityLabels,
        array $earnCodeLabels,
        mixed $hoursRendered,
        mixed $elementChange,
    ): array {
        return [
            'id' => $id,
            'projectLabel' => self::joined($projectLabels),
            'activityLabel' => self::joined($activityLabels),
            'earnCodeLabel' => self::joined($earnCodeLabels),
            'hoursRendered' => round((float) $hoursRendered, 2),
            'elementChange' => round((float) $elementChange, 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{
     *     id: string,
     *     referenceCode: string,
     *     memberName: string,
     *     kind: string,
     *     submittedOn: string,
     *     reason: ?string,
     *     entries: list<array<string, mixed>>
     * }
     */
    public static function report(
        string $id,
        string $referenceCode,
        string $memberName,
        string $kind,
        string $submittedOn,
        ?string $reason,
        array $entries,
    ): array {
        return [
            'id' => $id,
            'referenceCode' => $referenceCode,
            'memberName' => $memberName,
            'kind' => $kind,
            'submittedOn' => $submittedOn,
            'reason' => $reason,
            'entries' => $entries,
        ];
    }

    /**
     * Offset is a swapped pair of days, not extra hours on a normal weekday: original_work_day is
     * the day actually worked (often a weekend or holiday) and offset_work_day is the weekday taken
     * off. Leave is a request with no offset pair and no hours — overtime rows also live on this
     * table and must not occupy the calendar.
     */
    public static function occupancy(mixed $type, mixed $hours, ?string $originalWorkDay, ?string $offsetWorkDay): ?string
    {
        $kind = strtolower(trim((string) $type));
        if ($kind === 'offset' || ($originalWorkDay !== null && $offsetWorkDay !== null)) {
            return self::OCCUPANCY_OFFSET;
        }
        // Overtime is named rather than dropped: it occupies the day for a second overtime
        // request without occupying it for a report, which is the opposite of leave.
        if ($kind === 'overtime') {
            return self::OCCUPANCY_OVERTIME;
        }
        if (in_array($kind, ['holiday-work', 'holiday work'], true)) {
            return null;
        }
        if ($kind === 'leave') {
            return self::OCCUPANCY_LEAVE;
        }
        if ($originalWorkDay !== null || $offsetWorkDay !== null || self::hasHours($hours)) {
            return null;
        }

        return self::OCCUPANCY_LEAVE;
    }

    /**
     * The four statuses the portal knows, lower-cased the way every request payload sends them.
     * Anything unrecognised is Pending: a request nobody has decided on has not been decided on.
     */
    public static function requestStatus(mixed $status): string
    {
        if (self::isCancelled($status)) {
            return 'cancelled';
        }
        if (self::isRefused($status)) {
            return 'rejected';
        }

        return in_array(strtolower(trim((string) $status)), ['approved', 'accepted'], true)
            ? 'approved'
            : 'pending';
    }

    public static function isCancelled(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['cancelled', 'canceled', 'withdrawn'], true);
    }

    /**
     * A refused leave day is a day that was worked. Pending and approved both keep the member out
     * of the office, so only this one leaves the day free to file a report against.
     */
    public static function isRefused(mixed $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['rejected', 'declined', 'denied'], true);
    }

    /**
     * @param  list<string>  $dates
     * @return list<string>
     */
    public static function uniqueDates(array $dates): array
    {
        $unique = [];
        foreach ($dates as $date) {
            $value = substr($date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || in_array($value, $unique, true)) {
                continue;
            }
            $unique[] = $value;
        }
        sort($unique);

        return $unique;
    }

    /**
     * @return array{reports: int, days: int, daily: int, late: int, hours: float}
     */
    public static function counts(int $reports, int $days, int $daily, int $late, float $hours): array
    {
        return [
            'reports' => $reports,
            'days' => $days,
            'daily' => $daily,
            'late' => $late,
            'hours' => round($hours, 2),
        ];
    }

    /**
     * @param  list<string>  $labels
     */
    private static function joined(array $labels): string
    {
        $unique = [];
        foreach ($labels as $label) {
            $trimmed = trim($label);
            if ($trimmed === '' || in_array($trimmed, $unique, true)) {
                continue;
            }
            $unique[] = $trimmed;
        }

        return $unique === [] ? self::UNASSIGNED : implode(', ', $unique);
    }

    private static function hasHours(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return is_numeric($value);
    }
}
