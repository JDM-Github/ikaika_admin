<?php

namespace App\Support\Portal;

use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Which day it is for the person asking.
 *
 * The app runs on UTC and members do not. A report date, a leave day, and the seven-day filing
 * window are calendar dates rather than instants, so resolving them against the server's clock
 * puts a Manila member a day behind their own morning and a US member a day ahead of their own
 * evening -- the picker offers one day and the write refuses it.
 *
 * The client sends the zone it is standing in and every boundary is resolved there instead. An
 * IANA name is preferred because it carries its own daylight-saving rules; the numeric offset is
 * the fallback for a runtime whose Intl data cannot name the zone, and is enough to decide today.
 *
 * Nothing here is held between calls -- not the request, not the resolved zone. A Route memoizes
 * the controller it built, so under a long-lived application the second request through a route
 * reuses the first request's dependencies; a zone captured in a constructor would then answer for
 * whoever happened to call first. The current request is read per call for that reason.
 */
final class PortalTimezone
{
    public const NAME_HEADER = 'X-Portal-Timezone';

    public const OFFSET_HEADER = 'X-Portal-Timezone-Offset';

    // Real zones run from -12:00 to +14:00; anything outside is a client sending nonsense.
    private const MIN_OFFSET_MINUTES = -14 * 60;

    private const MAX_OFFSET_MINUTES = 12 * 60;

    private const MAX_NAME_LENGTH = 64;

    public function zone(): DateTimeZone
    {
        return $this->resolve(app('request'));
    }

    /**
     * Midnight where the member is standing, which is the only "today" any calendar here means.
     */
    public function today(): Carbon
    {
        return Carbon::now($this->zone())->startOfDay();
    }

    public function now(): Carbon
    {
        return Carbon::now($this->zone());
    }

    /**
     * Reads a stored calendar date as a day in the member's zone, so arithmetic against today
     * lands on whole days rather than on a fraction of one.
     */
    public function day(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, $this->zone())->startOfDay();
    }

    public function name(): string
    {
        return $this->zone()->getName();
    }

    private function resolve(Request $request): DateTimeZone
    {
        $named = $this->fromName($request->header(self::NAME_HEADER));
        if ($named !== null) {
            return $named;
        }

        $offset = $this->fromOffset($request->header(self::OFFSET_HEADER));
        if ($offset !== null) {
            return $offset;
        }

        return new DateTimeZone(config('app.timezone') ?? 'UTC');
    }

    private function fromName(mixed $value): ?DateTimeZone
    {
        if (! is_string($value)) {
            return null;
        }
        $name = trim($value);
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The header carries JavaScript's own convention: minutes to add to local time to reach UTC,
     * so Manila sends -480 and New York sends 300. Inverted here into an east-of-UTC offset.
     */
    private function fromOffset(mixed $value): ?DateTimeZone
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '' || preg_match('/^-?\d{1,4}$/', $raw) !== 1) {
            return null;
        }

        $minutes = (int) $raw;
        if ($minutes < self::MIN_OFFSET_MINUTES || $minutes > self::MAX_OFFSET_MINUTES) {
            return null;
        }

        $east = -$minutes;
        $sign = $east < 0 ? '-' : '+';
        $east = abs($east);

        try {
            return new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($east, 60), $east % 60));
        } catch (Throwable) {
            return null;
        }
    }
}
