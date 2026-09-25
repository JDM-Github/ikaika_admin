<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Administration / Missing Reports reads every member's filed days by employee id -- the read Manage
 * Reports makes for one member at a time, for all of them at once.
 */
class PortalMissingReportsTest extends TestCase
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

    public function test_an_admin_reads_every_members_filed_days_by_employee_id(): void
    {
        [$first, $second] = $this->twoMembers();
        $this->insertLine($first, '2026-06-09');
        // Two lines on one day are still one filed day.
        $this->insertLine($first, '2026-06-09');
        $this->insertLine($first, '2026-06-10');
        $this->insertLine($second, '2026-06-11');

        $rows = collect($this->read('?from=2026-06-01&to=2026-06-30')->json('data'))->keyBy('employeeId');

        $firstDays = $rows[(string) $first->getKey()]['days'];
        $this->assertContains('2026-06-09', $firstDays);
        $this->assertContains('2026-06-10', $firstDays);
        $this->assertSame(count($firstDays), count(array_unique($firstDays)));
        // Newest first, so the screen reads the latest day before any other off the front.
        $this->assertSame($firstDays, collect($firstDays)->sortDesc()->values()->all());
        $this->assertContains('2026-06-11', $rows[(string) $second->getKey()]['days']);
    }

    public function test_a_member_quiet_for_longer_than_the_window_keeps_their_last_day(): void
    {
        [$member] = $this->twoMembers();
        $this->insertLine($member, '2026-03-02');

        $row = collect($this->read('?from=2026-03-03&to=2026-03-03')->json('data'))
            ->firstWhere('employeeId', (string) $member->getKey());

        $this->assertIsArray($row);
        $this->assertSame('2026-03-02', $row['earlierDay']);
    }

    public function test_only_an_admin_reads_everyones_days(): void
    {
        [$member] = $this->twoMembers();

        $this->getJson('/api/development/portal/administration/missing-reports', $this->headersFor($member))
            ->assertForbidden();
    }

    private function read(string $query = ''): TestResponse
    {
        return $this->getJson('/api/development/portal/administration/missing-reports'.$query, $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('resource', 'missing-reports');
    }

    private function insertLine(Employee $employee, string $day): void
    {
        $id = (int) DB::connection('portal')->table('user_reports')->insertGetId([
            'report_date' => $day,
            'hours_rendered' => 8,
            'change_in_elements' => 0,
            'approval' => 'Approved',
        ]);
        DB::connection('portal')->table('employees_user_reports')->insert([
            'employee_id' => $employee->getKey(),
            'user_report_id' => $id,
        ]);
    }

    /**
     * @return list<Employee>
     */
    private function twoMembers(): array
    {
        $members = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit(2)
            ->get()
            ->all();
        $this->assertCount(2, $members);

        return $members;
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($admin);

        return $this->headersFor($admin);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
