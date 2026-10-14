<?php

namespace Tests\Concerns;

use App\Modules\Portal\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Access Rule scenarios walked through the API as real people, each signed in either as their role
 * ('default') or in Developer mode ('dev'). Runs on the seeded roster, inside the test transaction.
 */
trait ManagesPortalAccess
{
    /**
     * @var array<string, string>
     */
    private array $modeTokens = [];

    protected function clearAccessTables(): void
    {
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'access_lists', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        // The department heads' presets would make two of the admins unequal; the scenarios name their own powers.
        DB::connection('portal')->table('company_departments')->update(['head_employee_id' => null]);
        $this->modeTokens = [];
    }

    protected function change(string $mode, Employee $editor, Employee $target, array $body): TestResponse
    {
        return $this->asMode($mode, $editor)->patchJson("/api/development/portal/manage/access/{$target->id}", $body);
    }

    /**
     * @param  list<Employee>  $people
     */
    protected function setList(string $mode, Employee $editor, Employee $target, string $kind, array $people, string $key = 'manage.reports'): TestResponse
    {
        return $this->change($mode, $editor, $target, [
            'key' => $key,
            'action' => 'list',
            'mode' => $kind,
            'people' => array_map(static fn (Employee $person): int => (int) $person->id, $people),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sheetRule(string $mode, Employee $editor, Employee $target, string $key): array
    {
        $rule = collect($this->asMode($mode, $editor)->getJson("/api/development/portal/manage/access/{$target->id}")->assertOk()->json('data'))->firstWhere('key', $key);
        $this->assertIsArray($rule, "Rule {$key} is missing.");

        return $rule;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function ownPower(string $mode, Employee $employee, string $key): ?array
    {
        return collect($this->asMode($mode, $employee)->getJson('/api/development/portal/access')->assertOk()->json('data'))->firstWhere('key', $key);
    }

    /**
     * @return array<int, bool>
     */
    protected function editable(string $mode, Employee $viewer): array
    {
        $rows = $this->asMode($mode, $viewer)->getJson('/api/development/portal/manage/users?per_page=100')->assertOk()->json('data');

        return collect($rows)->mapWithKeys(fn (array $row): array => [(int) $row['id'] => $row['canEditAccess']])->all();
    }

    /**
     * @return list<string>
     */
    protected function reportsRoster(string $mode, Employee $viewer): array
    {
        return $this->asMode($mode, $viewer)->getJson('/api/development/portal/manage/reports')->assertOk()->json('employees.*.value');
    }

    // One sign-in per person and mode; Developer mode is switched on for that sign-in only.
    protected function asMode(string $mode, Employee $employee): self
    {
        $slot = $employee->id.':'.$mode;
        if (! isset($this->modeTokens[$slot])) {
            $token = (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
            $this->modeTokens[$slot] = $mode === 'dev' ? $this->developerModeOn($token) : $token;
        }

        return $this->withToken($this->modeTokens[$slot]);
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
}
