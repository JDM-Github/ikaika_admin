<?php

namespace Tests;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalJwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ChecksAirtableLive;

abstract class TestCase extends BaseTestCase
{
    use ChecksAirtableLive;

    // The portal's own network place on every request, so no test sends the server's IP lookup out to the internet.
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['X-Portal-Location' => 'Calamba City, Laguna, Philippines', 'X-Portal-Location-Source' => 'ip']);
        $this->markAirtableLedger();
    }

    // With AIRTABLE_LIVE_SCENARIOS=1 the test's ledger is dry-run against the live base first; the
    // rollback always follows, even when that check fails.
    protected function tearDown(): void
    {
        try {
            $this->checkAirtableLive();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Drops the rule check's own reads (catalog, presets, gifts, limits, switches, reaches,
     * Developer mode and the roster it judges), so a query budget measures only the endpoint.
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    protected function withoutRuleCheck(array $queries): array
    {
        return array_values(array_filter($queries, static function (string $sql): bool {
            if (preg_match('/from `(access_[a-z_]+|developer_modes)`/', $sql) === 1) {
                return false;
            }

            return ! (str_contains($sql, 'from `employees`') && ! str_contains($sql, 'where'));
        }));
    }

    // All Backups is developer-only while parked; gift-chain tests borrow its rules as powers only developers start with.
    protected function useBackupAsAPlainPower(): void
    {
        DB::connection('portal')->table('access_permissions')->where('permission_key', 'like', 'administration.backup%')->update(['min_tier' => null]);
    }

    // Switches Developer mode on for this signed-in token, as the Settings switch would; it lasts for that sign-in only.
    protected function developerModeOn(string $token): string
    {
        $payload = app(PortalJwt::class)->parse($token);
        DB::connection('portal')->table('developer_modes')->updateOrInsert(
            ['employee_id' => (int) $payload['sub']],
            ['active_session' => (string) $payload['sid']],
        );

        return $token;
    }

    /**
     * @return list<Employee>
     */
    protected function activeWhere(string $where, int $count): array
    {
        $employees = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->all();
        $this->assertCount($count, $employees);

        return $employees;
    }

    // The roster's own executive, never a shaped one; a scenario names the only one it needs.
    protected function rosterExecutive(): Employee
    {
        return $this->activeWhere("LOWER(COALESCE(role_level, '')) = 'executive'", 1)[0];
    }

    /**
     * People for a test's own world, shaped inside the transaction (2026-10-14, user): the first
     * active employees, never the executive, become admins and members whatever roles the roster
     * holds, so changing a role in the app never breaks a fixture.
     *
     * @return array{0: list<Employee>, 1: list<Employee>}
     */
    protected function shapedPeople(int $admins, int $members): array
    {
        $people = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit($admins + $members)
            ->get()
            ->all();
        $this->assertCount($admins + $members, $people);
        $adminPeople = array_slice($people, 0, $admins);
        $memberPeople = array_slice($people, $admins, $members);
        foreach ($adminPeople as $person) {
            $person->forceFill(['role' => 'admin', 'role_level' => null])->save();
        }
        foreach ($memberPeople as $person) {
            $person->forceFill(['role' => 'user', 'role_level' => null])->save();
        }

        return [$adminPeople, $memberPeople];
    }

    /**
     * What the ledger said changed on an employee after a given action (2026-10-20): the columns and
     * document slots the Airtable sync will send.
     *
     * @return array{columns: list<string>, slots: list<string>}
     */
    protected function ledgeredEmployeeChange(int $employeeId, int $afterActionId): array
    {
        $columns = [];
        $slots = [];
        $actions = Action::query()->where('id', '>', $afterActionId)->where('resource', 'employees')->where('record_id', (string) $employeeId)->get();
        foreach ($actions as $action) {
            $parameters = $action->parameters['parameters'] ?? [];
            $columns = [...$columns, ...($parameters['columns'] ?? [])];
            foreach ($parameters as $key => $value) {
                if (str_starts_with((string) $key, 'doc_') && $value === true) {
                    $slots[] = substr((string) $key, 4);
                }
            }
        }
        sort($columns);
        sort($slots);

        return ['columns' => array_values(array_unique($columns)), 'slots' => array_values(array_unique($slots))];
    }
}
