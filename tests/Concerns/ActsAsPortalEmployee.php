<?php

namespace Tests\Concerns;

use App\Modules\Portal\Models\Employee;

/**
 * Sessions for the workspace tests, taken from the real seeded roster rather than a
 * factory -- these suites run against the live schema and there is no factory for it.
 */
trait ActsAsPortalEmployee
{
    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return $this->headersFor($this->administrator());
    }

    /**
     * The workspace is an administrator's tool, so the default session is one.
     */
    protected function administrator(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where(function ($query): void {
                $query->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
                    ->orWhereRaw("LOWER(COALESCE(role_level, '')) = 'executive'");
            })
            ->whereNotNull('id_no')->where('id_no', '!=', '')->first();
        $this->assertNotNull($employee, 'No administrator in the portal database to browse with.');

        return $employee;
    }

    protected function member(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')->first();
        $this->assertNotNull($employee, 'No ordinary member in the portal database.');

        return $employee;
    }

    /**
     * @return array<string, string>
     */
    protected function headersFor(Employee $employee): array
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
