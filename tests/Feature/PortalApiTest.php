<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    public function test_playground_loads(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_catalog_lists_portal_on_the_development_channel(): void
    {
        $this->getJson('/api/development')
            ->assertOk()
            ->assertJsonPath('channel', 'development')
            ->assertJsonPath('convention', '/api/{channel}/{product}/{resource}')
            ->assertJsonFragment(['key' => 'portal', 'database' => 'test_portal_database']);
    }

    public function test_portal_module_reads_the_portal_database(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('health.database', 'test_portal_database');
    }

    public function test_portal_employees_endpoint(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/employees', [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('resource', 'employees')
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }

    public function test_disabled_estimator_is_not_served(): void
    {
        $this->getJson('/api/development/project-estimator')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Product [project-estimator] is registered but not enabled.');
    }
}
