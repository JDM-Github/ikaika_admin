<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Workspace\WorkspaceFieldType;
use App\Support\Workspace\WorkspaceIntrospector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WorkspaceSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_rail_lists_browsable_tables_with_exact_counts_and_hides_junctions(): void
    {
        $response = $this->getJson('/api/development/portal/_schema', $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('database', 'test_portal_database');

        $names = array_column($response->json('tables'), 'name');
        $this->assertContains('employees', $names);
        $this->assertContains('projects', $names);
        $this->assertContains('user_reports', $names);

        // A junction is an Airtable link field, not a table, so it never reaches the rail.
        $this->assertNotContains('employees_projects', $names);
        $this->assertNotContains('projects_user_reports', $names);
        $this->assertNotContains('user_reports_activity_codes', $names);

        $hidden = array_column($response->json('hidden'), 'name');
        $this->assertContains('employees_projects', $hidden);

        $projects = $this->tableRow($response->json('tables'), 'projects');
        $this->assertSame('project_name', $projects['title']);
        $this->assertGreaterThan(0, $projects['rows']);
    }

    public function test_a_value_table_that_looks_like_a_junction_stays_a_table(): void
    {
        $introspector = app(WorkspaceIntrospector::class);
        $junctions = $introspector->junctions('portal');

        // Composite primary key and no surrogate id, but element_name is a value,
        // not a foreign key -- an unrolled multipleSelects, not a link.
        $this->assertArrayNotHasKey('bim_form_elements_included', $junctions);
        $this->assertArrayHasKey('employees_projects', $junctions);
        $this->assertSame('role_on_project', $junctions['employees_projects']['qualifier']);
    }

    public function test_fields_carry_an_inferred_type_and_the_title_column_is_first(): void
    {
        $response = $this->getJson('/api/development/portal/_schema/projects', $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('table', 'projects')
            ->assertJsonPath('title', 'project_name');

        $fields = $response->json('fields');
        $this->assertSame('id', $fields[0]['name']);
        $this->assertSame(WorkspaceFieldType::ID, $fields[0]['type']);
        $this->assertSame('project_name', $fields[1]['name']);
        $this->assertSame(WorkspaceFieldType::TITLE, $fields[1]['type']);

        $byName = array_column($fields, null, 'name');
        $this->assertSame(WorkspaceFieldType::SELECT, $byName['status']['type']);
        $this->assertContains('Closed', $byName['status']['options']);
        $this->assertSame(WorkspaceFieldType::DATE, $byName['due_date']['type']);
        $this->assertSame(WorkspaceFieldType::BOOLEAN, $byName['is_ledger_details_updated']['type']);
        $this->assertSame(WorkspaceFieldType::DECIMAL, $byName['area_sqft']['type']);

        // A Windows path in google_drive_folder_path must not be typed as a url on
        // the strength of its name alone.
        $this->assertNotSame(WorkspaceFieldType::URL, $byName['google_drive_folder_path']['type']);
    }

    public function test_a_junction_becomes_a_link_field_on_both_of_its_tables(): void
    {
        $projects = $this->getJson('/api/development/portal/_schema/projects', $this->authHeaders());
        $projects->assertOk();
        $names = array_column($projects->json('fields'), 'name');
        $this->assertContains('link__clients', $names);

        $byName = array_column($projects->json('fields'), null, 'name');
        $this->assertSame(WorkspaceFieldType::LINK, $byName['link__clients']['type']);
        $this->assertSame('clients', $byName['link__clients']['target']);
        $this->assertSame('projects_clients', $byName['link__clients']['via']);

        $clients = $this->getJson('/api/development/portal/_schema/clients', $this->authHeaders());
        $clients->assertOk();
        $this->assertContains('link__projects', array_column($clients->json('fields'), 'name'));
    }

    public function test_secrets_never_appear_as_fields_and_a_junction_is_not_addressable(): void
    {
        $this->getJson('/api/development/portal/_schema/employees_projects', $this->authHeaders())
            ->assertNotFound();

        $this->getJson('/api/development/portal/_schema/no_such_table', $this->authHeaders())
            ->assertNotFound();

        $employees = $this->getJson('/api/development/portal/_schema/employees', $this->authHeaders());
        $employees->assertOk();
        $names = array_column($employees->json('fields'), 'name');
        $this->assertNotContains('password', $names);
        $this->assertNotContains('remember_token', $names);
    }

    public function test_the_schema_requires_a_session(): void
    {
        $this->getJson('/api/development/portal/_schema')->assertUnauthorized();
        $this->getJson('/api/development/portal/_schema/projects')->assertUnauthorized();
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     * @return array<string, mixed>
     */
    private function tableRow(array $tables, string $name): array
    {
        foreach ($tables as $table) {
            if ($table['name'] === $name) {
                return $table;
            }
        }

        $this->fail('The rail did not list '.$name.'.');
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->json('token');
        $this->assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
