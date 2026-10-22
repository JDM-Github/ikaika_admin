<?php

namespace Tests\Feature;

use App\Support\Airtable\AirtableClient;
use App\Support\Portal\PortalHolidays;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 2 (2026-10-22): the Calendar base is the holiday source. portal:sync-calendar mirrors it
 * into calendar_holidays; PortalHolidays reads that mirror, not the estimator database.
 */
class PortalCalendarSyncTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.airtable.token' => 'test-token',
            'services.airtable.base' => 'appTESTBASE000000',
            'services.airtable.bases.calendar' => 'appCALENDAR000000',
        ]);
        Cache::flush();
        DB::connection('portal')->table('calendar_holidays')->delete();
    }

    public function test_a_dry_run_reads_the_calendar_and_writes_nothing(): void
    {
        $this->fakeCalendar();

        $this->artisan('portal:sync-calendar --dry-run')
            ->expectsOutputToContain('3 holiday(s) in Calendar')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, DB::connection('portal')->table('calendar_holidays')->count());
    }

    public function test_holidays_are_mirrored_with_the_type_of_their_earn_code(): void
    {
        $this->fakeCalendar();

        $this->artisan('portal:sync-calendar')->assertExitCode(0);

        $rows = DB::connection('portal')->table('calendar_holidays')->orderBy('holiday_date')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['special', 'regular', 'double'], $rows->pluck('type')->all());
        $this->assertSame('Christmas Day', $rows[1]->name);
        $this->assertSame('recHOL0000000001', $rows[1]->airtable_record_id);

        $request = HttpRequest::create('/?from=2026-12-01&to=2026-12-31');
        $listed = app(PortalHolidays::class)->list($request);
        $this->assertCount(3, $listed['data']);
        $this->assertSame('regular', app(PortalHolidays::class)->typeForDate('2026-12-25'));
        $this->assertNull(app(PortalHolidays::class)->typeForDate('2026-12-23'));
    }

    public function test_a_second_run_updates_changes_and_removes_dropped_holidays(): void
    {
        $this->fakeCalendar();
        $this->artisan('portal:sync-calendar')->assertExitCode(0);

        $this->fakeCalendar(
            holidays: [
                $this->holiday('recHOL0000000001', '2026-12-25', 'Christmas Day (renamed)', 'recEARN000000001'),
                $this->holiday('recHOL0000000004', '2026-12-31', 'Last Day of the Year', 'recEARN000000002'),
            ],
        );
        $this->artisan('portal:sync-calendar')->assertExitCode(0);

        $rows = DB::connection('portal')->table('calendar_holidays')->orderBy('holiday_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('Christmas Day (renamed)', $rows[0]->name);
        $this->assertSame('Last Day of the Year', $rows[1]->name);
        $this->assertFalse(DB::connection('portal')->table('calendar_holidays')->where('airtable_record_id', 'recHOL0000000002')->exists());

        $this->artisan('portal:sync-calendar')
            ->expectsOutputToContain('0 new, 0 changed, 0 gone')
            ->assertExitCode(0);
    }

    public function test_records_follow_airtable_offsets(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['records' => [['id' => 'recA000000000001', 'fields' => []]], 'offset' => 'next'])
                : Http::response(['records' => [['id' => 'recA000000000002', 'fields' => []]]]);
        });

        $records = app(AirtableClient::class)->records('Holidays', 'calendar');

        $this->assertSame(['recA000000000001', 'recA000000000002'], array_column($records, 'id'));
        $this->assertSame(2, $calls);
    }

    /** @var list<array{id: string, fields: array<string, mixed>}> */
    private array $earnCodes = [];

    /** @var list<array{id: string, fields: array<string, mixed>}> */
    private array $holidays = [];

    private bool $faked = false;

    /**
     * @param  list<array{id: string, fields: array<string, mixed>}>|null  $earnCodes
     * @param  list<array{id: string, fields: array<string, mixed>}>|null  $holidays
     */
    private function fakeCalendar(?array $earnCodes = null, ?array $holidays = null): void
    {
        $this->earnCodes = $earnCodes ?? [
            ['id' => 'recEARN000000001', 'fields' => ['Description' => '13 Regular Holiday']],
            ['id' => 'recEARN000000002', 'fields' => ['Description' => '09 Special Holiday']],
            ['id' => 'recEARN000000003', 'fields' => ['Description' => '25 Double Holiday']],
        ];
        $this->holidays = $holidays ?? [
            $this->holiday('recHOL0000000001', '2026-12-25', 'Christmas Day', 'recEARN000000001'),
            $this->holiday('recHOL0000000002', '2026-12-24', 'Christmas Eve', 'recEARN000000002'),
            $this->holiday('recHOL0000000003', '2026-12-30', 'Rizal Day', 'recEARN000000003'),
        ];
        if ($this->faked) {
            return;
        }
        $this->faked = true;

        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            return match (true) {
                str_ends_with($path, '/Earn Codes') => Http::response(['records' => $this->earnCodes]),
                str_ends_with($path, '/Holidays') => Http::response(['records' => $this->holidays]),
                default => Http::response(['error' => ['type' => 'NOT_FOUND', 'message' => $path]], 404),
            };
        });
    }

    /**
     * @return array{id: string, fields: array<string, mixed>}
     */
    private function holiday(string $id, string $date, string $name, string $earnCode): array
    {
        return [
            'id' => $id,
            'fields' => ['Date' => $date, 'Name' => $name, 'Earn Code' => [$earnCode]],
        ];
    }
}
