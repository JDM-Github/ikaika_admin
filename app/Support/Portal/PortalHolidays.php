<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Calendar / Holidays: the company holiday calendar, read from the portal mirror of the Calendar
 * base (2026-10-22, user). portal:sync-calendar fills calendar_holidays; the estimator calendar is
 * no longer read.
 *
 * A missing mirror or a read failure returns an empty list rather than an error, so a migration or a
 * disabled Airtable cannot take a member's own report history down with it.
 */
final class PortalHolidays
{
    public const CACHE_TTL_SECONDS = 600;

    public const MAX_RANGE_YEARS = 5;

    private const CACHE_VERSION_KEY = 'portal:calendar:holidays:version';

    public function __construct(private readonly PortalTimezone $timezone) {}

    private const TYPE_REGULAR = 'regular';

    private const TYPE_SPECIAL = 'special';

    private const TYPE_DOUBLE = 'double';

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{holidays: int, regular: int, special: int},
     *     range: array{from: string, to: string}
     * }
     */
    public function list(Request $request): array
    {
        [$from, $to] = $this->dateRange($request);

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:calendar:holidays:'.$version.':'.$from.':'.$to;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($from, $to): array {
            return $this->build($from, $to);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * A day's holiday type from the estimator's calendar: double, regular or special; null on a workday.
     */
    public function typeForDate(string $date): ?string
    {
        $row = $this->rows($date, $date)[0] ?? null;
        if ($row === null) {
            return null;
        }

        return is_string($row->type ?? null) ? $row->type : self::TYPE_SPECIAL;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{holidays: int, regular: int, special: int},
     *     range: array{from: string, to: string}
     * }
     */
    private function build(string $from, string $to): array
    {
        $rows = $this->rows($from, $to);

        $data = [];
        $regular = 0;
        $special = 0;
        foreach ($rows as $row) {
            $type = is_string($row->type ?? null) ? $row->type : self::TYPE_SPECIAL;
            $id = is_string($row->airtable_record_id ?? null) && $row->airtable_record_id !== ''
                ? $row->airtable_record_id
                : (string) $row->id;
            $data[] = PortalHolidayPresenter::holiday(
                $id,
                (string) $row->holiday_date,
                is_string($row->name) ? $row->name : null,
                $type,
            );
            if ($type === self::TYPE_REGULAR) {
                $regular++;
            } else {
                $special++;
            }
        }

        return [
            'section' => 'calendar',
            'resource' => 'holidays',
            'data' => $data,
            'counts' => [
                'holidays' => count($data),
                'regular' => $regular,
                'special' => $special,
            ],
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * The portal mirror written by portal:sync-calendar. A local table keeps the calendar readable
     * when Airtable is down, and a missing table is an empty calendar, never an error.
     *
     * @return list<object>
     */
    private function rows(string $from, string $to): array
    {
        try {
            if (! Schema::connection('portal')->hasTable('calendar_holidays')) {
                return [];
            }

            return DB::connection('portal')
                ->table('calendar_holidays')
                ->select(['id', 'airtable_record_id', 'holiday_date', 'name', 'type'])
                ->whereNotNull('holiday_date')
                ->whereBetween('holiday_date', [$from, $to])
                ->orderBy('holiday_date')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Regular, Special and Double are what the pay multiplier turns on, and the Calendar Earn Code
     * description is where the source records it. Anything unrecognised is special.
     */
    public static function classifyDescription(mixed $description): string
    {
        $value = strtolower(trim((string) $description));
        if (str_contains($value, 'double')) {
            return self::TYPE_DOUBLE;
        }

        return str_contains($value, 'regular holiday') ? self::TYPE_REGULAR : self::TYPE_SPECIAL;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(Request $request): array
    {
        $fromRaw = trim((string) $request->query('from', ''));
        $toRaw = trim((string) $request->query('to', ''));

        $from = $fromRaw === ''
            ? $this->timezone->today()->subYears(self::MAX_RANGE_YEARS)->startOfYear()
            : $this->parseDate($fromRaw);
        $ceiling = $from->copy()->addYears(self::MAX_RANGE_YEARS)->endOfYear();
        $to = $toRaw === '' ? $ceiling->copy() : $this->parseDate($toRaw);

        if ($to->lt($from)) {
            abort(422, 'The to date must be on or after the from date.');
        }

        if ($to->gt($ceiling)) {
            $to = $ceiling;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function parseDate(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        if (! $date instanceof Carbon || $date->toDateString() !== $value) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $date->startOfDay();
    }
}
