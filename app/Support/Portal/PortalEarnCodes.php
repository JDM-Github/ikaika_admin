<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The earn code a filing or request carries, matched by its number and name rather than a loose
 * substring (2026-10-22, user): the import order is not the code order, so "regular" alone once
 * picked "24 Regular Holiday + Rest Day ..." for every new report.
 *
 * Daily and late reports are always 01 Regular Working Day. An overtime request takes the day's
 * rest-day/holiday variant; an offset request is always a regular working day; a leave request takes
 * the code its leave type names, when the catalogue has one.
 */
final class PortalEarnCodes
{
    public const REGULAR_WORKING_DAY = '01 Regular Working Day';

    private const REGULAR_OVERTIME = '02 Regular Overtime';

    private const REST_DAY_OVERTIME = '06 Rest Day Overtime';

    private const SPECIAL_HOLIDAY_OVERTIME = '10 Special Holiday Overtime';

    private const REGULAR_HOLIDAY_OVERTIME = '14 Regular Holiday Overtime';

    private const SPECIAL_HOLIDAY_REST_DAY_OVERTIME = '18 Special Holiday + Rest Day Overtime';

    private const REGULAR_HOLIDAY_REST_DAY_OVERTIME = '22 Regular Holiday + Rest Day Overtime';

    private const DOUBLE_HOLIDAY_OVERTIME = '26 Double Holiday Overtime';

    private const DOUBLE_HOLIDAY_REST_DAY_OVERTIME = '30 Double Holiday + Rest Day Overtime';

    /** @var list<object>|null */
    private ?array $rows = null;

    public function regularWorkingDayId(): ?int
    {
        return $this->id(self::REGULAR_WORKING_DAY);
    }

    public function overtimeIdFor(string $date): ?int
    {
        $holiday = app(PortalHolidays::class)->typeForDate($date);
        $restDay = Carbon::parse($date)->isWeekend();

        $label = match (true) {
            $holiday === 'double' && $restDay => self::DOUBLE_HOLIDAY_REST_DAY_OVERTIME,
            $holiday === 'double' => self::DOUBLE_HOLIDAY_OVERTIME,
            $holiday === 'regular' && $restDay => self::REGULAR_HOLIDAY_REST_DAY_OVERTIME,
            $holiday === 'special' && $restDay => self::SPECIAL_HOLIDAY_REST_DAY_OVERTIME,
            $holiday === 'regular' => self::REGULAR_HOLIDAY_OVERTIME,
            $holiday === 'special' => self::SPECIAL_HOLIDAY_OVERTIME,
            $restDay => self::REST_DAY_OVERTIME,
            default => self::REGULAR_OVERTIME,
        };

        return $this->id($label) ?? $this->id(self::REGULAR_OVERTIME);
    }

    public function leaveIdFor(string $leaveType): ?int
    {
        $name = $this->name($leaveType);
        if ($name === '') {
            return null;
        }
        foreach ($this->rows() as $row) {
            if ($this->name((string) ($row->description ?? '')) === $name) {
                return (int) $row->id;
            }
        }

        return null;
    }

    public function id(string $description): ?int
    {
        $target = $this->key($description);
        foreach ($this->rows() as $row) {
            if ($this->key((string) ($row->description ?? '')) === $target) {
                return (int) $row->id;
            }
        }

        [$code, $name] = $this->split($description);
        if ($code === null || $name === '') {
            return null;
        }
        foreach ($this->rows() as $row) {
            [$rowCode, $rowName] = $this->split((string) ($row->description ?? ''));
            if ($rowCode === $code && $rowName === $name) {
                return (int) $row->id;
            }
        }

        return null;
    }

    /**
     * The description without its leading number: "01 Vacation Leave" -> "vacation leave".
     */
    private function name(string $description): string
    {
        [, $name] = $this->split($description);

        return $name !== '' ? $name : $this->key($description);
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function split(string $description): array
    {
        $value = $this->key($description);
        if (preg_match('/^(\d{1,2})\s+(.+)$/u', $value, $match) !== 1) {
            return [null, $value];
        }

        return [$match[1], trim($match[2])];
    }

    private function key(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    /**
     * @return list<object>
     */
    private function rows(): array
    {
        return $this->rows ??= $this->connection()
            ->table('earn_codes')
            ->orderBy('id')
            ->get(['id', 'description'])
            ->all();
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
