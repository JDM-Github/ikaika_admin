<?php

namespace App\Support\Portal;

use App\Modules\ProjectEstimator\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Calendar / Holidays: the company holiday calendar, read from the estimator product.
 *
 * The table is owned by the estimator, which schedules against it. The portal only reads it, so
 * this is a projection and never a write. Crossing products is done through the Holiday model's
 * own connection rather than a cross-schema JOIN, because sql/portal/schema.sql opens with
 * DROP DATABASE and anything reaching between the two schemas by key would not survive a reseed.
 *
 * A failure here returns an empty list rather than an error. The portal calendar degrades to what
 * it showed before holidays existed, and a product that is disabled or mid-migration must not be
 * able to take a member's own report history down with it.
 */
final class PortalHolidays
{
    public const CACHE_TTL_SECONDS = 600;

    public const MAX_RANGE_YEARS = 5;

    private const CACHE_VERSION_KEY = 'portal:calendar:holidays:version';

    private const TYPE_REGULAR = 'regular';

    private const TYPE_SPECIAL = 'special';

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
            $type = $this->classify($row->base_earn_code ?? null);
            $data[] = PortalHolidayPresenter::holiday(
                (string) $row->id,
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
     * The null-dated legend rows are excluded here rather than by the caller, so no reader of this
     * class has to know the source table doubles as its own key.
     *
     * @return list<object>
     */
    private function rows(string $from, string $to): array
    {
        try {
            return Holiday::query()
                ->toBase()
                ->select([
                    'holidays.id',
                    'holidays.holiday_date',
                    'holidays.name',
                    'earn_codes_v2.description as base_earn_code',
                ])
                ->leftJoin('holidays_earn_codes_v2', function ($join): void {
                    $join->on('holidays_earn_codes_v2.holiday_id', '=', 'holidays.id')
                        ->where('holidays_earn_codes_v2.variant', '=', 'base');
                })
                ->leftJoin(
                    'earn_codes_v2',
                    'earn_codes_v2.id',
                    '=',
                    'holidays_earn_codes_v2.earn_code_v2_id',
                )
                ->whereNotNull('holidays.holiday_date')
                ->whereBetween('holidays.holiday_date', [$from, $to])
                ->orderBy('holidays.holiday_date')
                ->get()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Regular and Special are the two the pay multiplier turns on, and the label is the only place
     * the source records it. Anything unrecognised is treated as special, the lesser of the two.
     */
    private function classify(mixed $description): string
    {
        $value = strtolower(trim((string) $description));

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
            ? Carbon::today()->subYears(self::MAX_RANGE_YEARS)->startOfYear()
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
