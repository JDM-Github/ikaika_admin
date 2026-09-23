<?php

namespace Tests\Unit;

use App\Support\Portal\PortalTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortalTimezoneTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * The zone reads the request being handled right now rather than one handed to it once, so
     * the test binds it the same way the framework does.
     */
    private function forHeaders(array $headers): PortalTimezone
    {
        $request = Request::create('/api/development/portal/reports/submitted/days');
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
        $this->app->instance('request', $request);

        return new PortalTimezone;
    }

    public function test_a_named_zone_is_preferred_because_it_carries_its_own_daylight_rules(): void
    {
        $zone = $this->forHeaders([PortalTimezone::NAME_HEADER => 'Asia/Manila']);

        $this->assertSame('Asia/Manila', $zone->name());
    }

    public function test_the_offset_stands_in_when_the_runtime_cannot_name_the_zone(): void
    {
        // JavaScript sends minutes to add to local time to reach UTC, so Manila is -480.
        $this->assertSame('+08:00', $this->forHeaders([
            PortalTimezone::OFFSET_HEADER => '-480',
        ])->name());

        $this->assertSame('-05:00', $this->forHeaders([
            PortalTimezone::OFFSET_HEADER => '300',
        ])->name());

        $this->assertSame('+05:45', $this->forHeaders([
            PortalTimezone::OFFSET_HEADER => '-345',
        ])->name());
    }

    public function test_nonsense_falls_back_to_the_application_zone(): void
    {
        $app = config('app.timezone');

        $this->assertSame($app, $this->forHeaders([])->name());
        $this->assertSame($app, $this->forHeaders([
            PortalTimezone::NAME_HEADER => 'Middle/Earth',
        ])->name());
        // Outside the real -12:00..+14:00 span, so it is a client sending junk.
        $this->assertSame($app, $this->forHeaders([
            PortalTimezone::OFFSET_HEADER => '-2000',
        ])->name());
        $this->assertSame($app, $this->forHeaders([
            PortalTimezone::OFFSET_HEADER => 'yesterday',
        ])->name());
    }

    public function test_two_members_standing_in_different_zones_disagree_on_today(): void
    {
        // 22:00 UTC: already tomorrow morning in Manila, still this afternoon in New York.
        Carbon::setTestNow(Carbon::parse('2026-09-01 22:00:00', 'UTC'));

        // One at a time: the zone reads whichever request is being handled, so binding the
        // second would answer for the first as well.
        $this->assertSame('2026-09-02', $this->forHeaders([
            PortalTimezone::NAME_HEADER => 'Asia/Manila',
        ])->today()->toDateString());

        $this->assertSame('2026-09-01', $this->forHeaders([
            PortalTimezone::NAME_HEADER => 'America/New_York',
        ])->today()->toDateString());
    }

    public function test_a_stored_day_is_read_in_the_members_own_zone(): void
    {
        $manila = $this->forHeaders([PortalTimezone::NAME_HEADER => 'Asia/Manila']);

        $day = $manila->day('2026-09-02');

        // Midnight where they are standing, so comparing it with today compares two wall clocks.
        $this->assertSame('2026-09-02 00:00:00', $day->toDateTimeString());
        $this->assertSame('Asia/Manila', $day->getTimezone()->getName());
    }
}
