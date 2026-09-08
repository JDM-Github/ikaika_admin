<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    public function test_login_issues_a_jwt_for_an_active_employee_id_number(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);
        $this->assertNotEmpty($employee->id_no);

        $response = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.id_no', $employee->id_no)
            ->assertJsonPath('employee.role', $employee->role)
            ->assertJsonMissingPath('employee.bank_account_number');

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertCount(3, explode('.', $token));
        $this->assertSame((int) config('products.jwt_ttl'), $response->json('expires_in'));

        $payloadJson = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'));
        $payload = json_decode((string) $payloadJson, true);
        $this->assertIsArray($payload);
        $this->assertSame((string) $employee->getKey(), $payload['sub']);
        $this->assertSame($employee->id_no, $payload['id_no']);
        $this->assertSame($employee->role, $payload['role']);

        $log = PortalLog::query()
            ->where('employee_id', $employee->getKey())
            ->where('action', 'POST')
            ->where('resource', 'auth.login')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('{UserName|You} signed in to the portal', $log->message);
    }

    public function test_login_rejects_an_unknown_id_number(): void
    {
        $this->postJson('/api/development/portal/auth/login', [
            'id_no' => '000000-0000',
        ])->assertUnauthorized();
    }

    public function test_login_requires_an_id_number(): void
    {
        $this->postJson('/api/development/portal/auth/login', [])
            ->assertUnprocessable();
    }

    public function test_me_and_employees_use_the_bearer_token_instead_of_an_id_query(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.role', $employee->role);

        $this->getJson('/api/development/portal/employees?per_page=1', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('resource', 'employees');
    }

    public function test_employees_without_a_token_are_unauthorized(): void
    {
        $this->getJson('/api/development/portal/employees')
            ->assertUnauthorized();
    }

    public function test_login_rejects_an_inactive_employee(): void
    {
        $employee = Employee::query()
            ->whereRaw('LOWER(status) != ?', ['active'])
            ->first();
        if ($employee === null) {
            $this->markTestSkipped('No inactive employee in the portal database.');
        }

        $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $this->freezeTime();
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->travel(((int) config('products.jwt_ttl')) + 1)->seconds();

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => "Bearer {$token}",
        ])->assertUnauthorized();
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => 'Bearer '.implode('.', $parts),
        ])->assertUnauthorized();
    }

    public function test_health_reports_a_valid_session_from_the_bearer_token(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/health', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('session.ok', true);
    }

    public function test_health_without_a_token_does_not_include_a_session(): void
    {
        $this->getJson('/api/development/portal/health')
            ->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonMissingPath('session');
    }

    public function test_health_marks_an_expired_token_so_the_portal_can_sign_out(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $this->freezeTime();
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->travel(((int) config('products.jwt_ttl')) + 1)->seconds();

        $this->getJson('/api/development/portal/health', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('session.ok', false);
    }
}
