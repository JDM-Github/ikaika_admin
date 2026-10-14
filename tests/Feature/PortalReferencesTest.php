<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / References: the reference data a project is filed under. An administrator-tier power since
 * 2026-10-09: `projects.references` opens and reads the page, `projects.references.edit` covers the
 * four list tabs' writes. Every working call signs in an admin, in Default mode.
 */
class PortalReferencesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/references';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    // The page is an administrator's now: every working call signs in an admin, in Default mode.
    private function adminToken(): string
    {
        return $this->token($this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'"));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function add(string $token, array $payload): TestResponse
    {
        return $this->withToken($token)->postJson(self::BASE.'/scopes', $payload);
    }

    /**
     * @return list<array{id: string, code: string, name: string, department: string}>
     */
    private function scopes(TestResponse $response): array
    {
        return $response->json('data.scopes');
    }

    public function test_the_page_follows_its_rule(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
        $this->withToken($this->token($admin))->getJson(self::BASE)->assertOk();
    }

    // Edit references splits from the page: without it an admin still reads, and every write is refused.
    public function test_edit_references_gates_every_write_on_its_own(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);
        DB::connection('portal')->table('access_presets')->where('role', 'admin')
            ->where('permission_key', 'projects.references.edit')->delete();

        $this->withToken($token)->getJson(self::BASE)->assertOk();
        $this->add($token, ['code' => 'MIS-9099', 'name' => 'Refused', 'department' => 'MIS'])->assertForbidden();
        $this->withToken($token)->patchJson(self::BASE.'/scopes/1', ['code' => 'X', 'name' => 'X', 'department' => 'MIS'])->assertForbidden();
        $this->withToken($token)->deleteJson(self::BASE.'/scopes/1')->assertForbidden();
    }

    public function test_the_list_returns_each_scope_with_its_department(): void
    {
        $scopes = $this->scopes($this->withToken($this->adminToken())->getJson(self::BASE)->assertOk());
        $first = $scopes[0] ?? null;

        $this->assertNotNull($first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('department', $first);
    }

    public function test_a_scope_is_added_edited_and_removed(): void
    {
        $token = $this->adminToken();

        $added = $this->add($token, ['code' => 'MIS-9001', 'name' => 'Test drafting', 'department' => 'MIS'])->assertOk();
        $row = collect($this->scopes($added))->firstWhere('code', 'MIS-9001');
        $this->assertNotNull($row);
        $this->assertSame('Test drafting', $row['name']);
        $this->assertSame('MIS', $row['department']);

        $this->withToken($token)->patchJson(self::BASE.'/scopes/'.$row['id'], ['code' => 'MIS-9001', 'name' => 'Test drafting v2', 'department' => 'MIS'])
            ->assertOk()
            ->assertJsonPath('data.scopes', fn (array $scopes): bool => collect($scopes)->contains(fn (array $scope): bool => $scope['id'] === $row['id'] && $scope['name'] === 'Test drafting v2'));

        $this->withToken($token)->deleteJson(self::BASE.'/scopes/'.$row['id'])
            ->assertOk()
            ->assertJsonPath('data.scopes', fn (array $scopes): bool => collect($scopes)->doesntContain(fn (array $scope): bool => $scope['id'] === $row['id']));

        // Each write is ledgered for the Airtable sync: the add whole, the edit by what it changed (2026-10-14).
        $actions = DB::connection('core')->table('actions')->where('resource', 'references.activity_codes')->where('record_id', (string) $row['id'])
            ->orderBy('id')->get(['action_type', 'parameters'])
            ->map(fn (object $action): array => [$action->action_type, collect(array_keys((array) (json_decode((string) $action->parameters, true)['parameters']['fields'] ?? [])))->sort()->values()->all()])->all();
        $this->assertSame([['add', ['department', 'id_no', 'name']], ['edit', ['name']], ['delete', []]], $actions);
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $token = $this->adminToken();

        $this->add($token, ['code' => 'MIS-9002', 'name' => 'First', 'department' => 'MIS'])->assertOk();
        $this->add($token, ['code' => 'mis-9002', 'name' => 'Second', 'department' => 'MIS'])->assertStatus(422);
    }

    // The reported ask: each department keeps a scope tree like Engineering's, edited here by WBS code.
    public function test_a_departments_scope_tree_is_added_renamed_and_removed(): void
    {
        $token = $this->adminToken();
        $before = (int) DB::connection('core')->table('actions')->max('id');
        $tree = fn (TestResponse $response): array => $response->json('data.scopeTree');
        $add = fn (array $body): TestResponse => $this->withToken($token)->postJson(self::BASE.'/tree-scopes', $body);

        // Engineering's tree is the one from Airtable.
        $engineering = collect($tree($this->withToken($token)->getJson(self::BASE)->assertOk()))->firstWhere('code', '100');
        $this->assertSame(['Engineering', 'Project Initiation'], [$engineering['department'], $engineering['name']]);

        $root = collect($tree($add(['code' => '910', 'name' => 'MIS Planning', 'department' => 'mis'])->assertOk()))->firstWhere('code', '910');
        $this->assertSame('MIS', $root['department']);
        $add(['code' => '910-01', 'name' => 'Sprint planning', 'department' => 'MIS'])->assertOk();
        $added = $add(['code' => '910-01-01', 'name' => 'Write the sprint goal', 'department' => 'MIS'])->assertOk();
        $this->assertSame('Write the sprint goal', collect($tree($added))->firstWhere('code', '910')['children'][0]['children'][0]['name']);

        $add(['code' => '910', 'name' => 'Again', 'department' => 'MIS'])->assertStatus(422)->assertJsonPath('message', 'That scope code is already used.');
        $add(['code' => '91', 'name' => 'Short', 'department' => 'MIS'])->assertStatus(422);
        $add(['code' => '100-09', 'name' => 'Not MIS', 'department' => 'MIS'])->assertStatus(422);
        $add(['code' => '920', 'name' => 'Nowhere', 'department' => 'Nowhere'])->assertStatus(422);

        $this->withToken($token)->patchJson(self::BASE.'/tree-scopes/910-01', ['name' => 'Sprints'])->assertOk()
            ->assertJsonPath('data.scopeTree', fn (array $roots): bool => collect($roots)->firstWhere('code', '910')['children'][0]['name'] === 'Sprints');
        $this->withToken($token)->patchJson(self::BASE.'/tree-scopes/999', ['name' => 'X'])->assertNotFound();

        // A scope with sub-scopes, or one a project holds, stays.
        $this->withToken($token)->deleteJson(self::BASE.'/tree-scopes/910-01')->assertStatus(422)->assertJsonPath('message', 'Remove its sub-scopes first.');
        $projectId = (int) DB::connection('portal')->table('projects')->value('id');
        DB::connection('portal')->table('projects_wbs_scopes')->insert(['project_id' => $projectId, 'wbs_code' => '910-01-01']);
        $this->withToken($token)->deleteJson(self::BASE.'/tree-scopes/910-01-01')->assertStatus(422)->assertJsonPath('message', 'Projects use this scope. Take it off them first.');
        DB::connection('portal')->table('projects_wbs_scopes')->where('wbs_code', '910-01-01')->delete();
        $this->withToken($token)->deleteJson(self::BASE.'/tree-scopes/910-01-01')->assertOk();
        $this->withToken($token)->deleteJson(self::BASE.'/tree-scopes/910-01')->assertOk();
        $this->assertSame([], collect($tree($this->withToken($token)->deleteJson(self::BASE.'/tree-scopes/910')->assertOk()))->where('code', '910')->all());

        // Each tier's change is ledgered under its own tier, so the sync reaches the right Airtable table (2026-10-14).
        $ledgered = DB::connection('core')->table('actions')->where('resource', 'like', 'references.scopes.%')->where('id', '>', $before)
            ->orderBy('id')->get(['resource', 'action_type'])->map(fn (object $action): string => $action->resource.':'.$action->action_type)->all();
        $this->assertSame([
            'references.scopes.t1:add', 'references.scopes.t2:add', 'references.scopes.t3:add', 'references.scopes.t2:edit',
            'references.scopes.t3:delete', 'references.scopes.t2:delete', 'references.scopes.t1:delete',
        ], $ledgered);
    }

    public function test_a_missing_scope_cannot_be_changed(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->patchJson(self::BASE.'/scopes/99999999', ['code' => 'X', 'name' => 'X', 'department' => 'MIS'])->assertNotFound();
        $this->withToken($token)->deleteJson(self::BASE.'/scopes/99999999')->assertNotFound();
    }

    public function test_a_project_type_is_added_edited_and_removed(): void
    {
        $token = $this->adminToken();

        $added = $this->withToken($token)->postJson(self::BASE.'/job-types', ['department' => 'MIS', 'name' => 'Test type 9901'])->assertOk();
        $row = collect($added->json('data.jobTypes'))->firstWhere('name', 'Test type 9901');
        $this->assertNotNull($row);
        $this->assertSame('MIS', $row['department']);

        $this->withToken($token)->patchJson(self::BASE.'/job-types/'.$row['id'], ['department' => 'MIS', 'name' => 'Test type 9902'])
            ->assertOk()
            ->assertJsonPath('data.jobTypes', fn (array $types): bool => collect($types)->contains(fn (array $type): bool => $type['id'] === $row['id'] && $type['name'] === 'Test type 9902'));

        $this->withToken($token)->postJson(self::BASE.'/job-types', ['department' => 'MIS', 'name' => 'test type 9902'])->assertStatus(422);

        $this->withToken($token)->deleteJson(self::BASE.'/job-types/'.$row['id'])
            ->assertOk()
            ->assertJsonPath('data.jobTypes', fn (array $types): bool => collect($types)->doesntContain(fn (array $type): bool => $type['id'] === $row['id']));
    }

    public function test_a_department_is_added_described_and_removed(): void
    {
        $token = $this->adminToken();

        $added = $this->withToken($token)->postJson(self::BASE.'/departments', ['name' => 'Test Dept', 'description' => '  Drafts the test work.  '])->assertOk();
        $row = collect($added->json('data.departments'))->firstWhere('name', 'Test Dept');
        $this->assertNotNull($row);
        $this->assertSame('Drafts the test work.', $row['description']);
        $this->assertSame([], $row['members']);

        $this->withToken($token)->postJson(self::BASE.'/departments', ['name' => 'test dept'])->assertStatus(422);

        // Only the description is editable: the name is how projects, people, types and scopes are filed.
        $this->withToken($token)->patchJson(self::BASE.'/departments/'.$row['id'], ['description' => '   '])
            ->assertOk()
            ->assertJsonPath('data.departments', fn (array $departments): bool => collect($departments)->contains(fn (array $department): bool => $department['id'] === $row['id'] && $department['description'] === null));
        $this->withToken($token)->patchJson(self::BASE.'/departments/'.$row['id'], ['description' => 'Kept.'])->assertOk();
        $this->withToken($token)->patchJson(self::BASE.'/departments/99999999', ['description' => 'Gone'])->assertNotFound();

        $this->withToken($token)->deleteJson(self::BASE.'/departments/'.$row['id'])->assertOk();
        $this->withToken($token)->deleteJson(self::BASE.'/departments/'.$row['id'])->assertNotFound();
    }

    public function test_a_department_carries_its_active_members(): void
    {
        $departments = $this->withToken($this->adminToken())->getJson(self::BASE)->assertOk()->json('data.departments');
        $staffed = collect($departments)->first(fn (array $department): bool => count($department['members']) > 0);
        $this->assertNotNull($staffed, 'No department with active members to check against.');

        $count = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [mb_strtolower(trim($staffed['name']))])
            ->count();
        $this->assertCount($count, $staffed['members']);
    }

    // The reported ask: a new client, with the two-digit code its project numbers carry.
    public function test_a_client_is_added_with_the_next_free_code_and_numbers_its_projects(): void
    {
        $token = $this->adminToken();
        $page = $this->withToken($token)->getJson(self::BASE)->assertOk();
        $next = (string) $page->json('data.nextClientCode');
        $this->assertMatchesRegularExpression('/^\d{2}$/', $next);
        $this->assertNotContains($next, array_column($page->json('data.clients'), 'code'));
        $withProjects = collect($page->json('data.clients'))->firstWhere('projects', '>', 0);
        $this->assertNotNull($withProjects, 'No client with projects to check the count against.');

        $added = collect($this->withToken($token)->postJson(self::BASE.'/clients', ['name' => '  Kona   Builders ', 'code' => $next])
            ->assertOk()->json('data.clients'))->firstWhere('code', $next);
        $this->assertSame(['name' => 'Kona Builders', 'code' => $next, 'projects' => 0], collect($added)->only(['name', 'code', 'projects'])->all());
        $this->assertTrue(DB::connection('core')->table('actions')->where('resource', 'references.clients')->where('action_type', 'add')
            ->where('record_id', (string) $added['id'])->exists());

        // Add Project numbers the new client's first project with its code.
        $option = collect($this->withToken($token)->getJson('/api/development/portal/projects/options')->assertOk()->json('data.clients'))->firstWhere('id', $added['id']);
        $this->assertSame(now('Asia/Manila')->format('y').$next.'01', $option['nextNumber']);
    }

    public function test_a_client_needs_a_free_name_and_a_free_two_digit_code(): void
    {
        $token = $this->adminToken();
        $taken = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data.clients'))->first();
        $free = (string) $this->withToken($token)->getJson(self::BASE)->json('data.nextClientCode');

        $this->withToken($token)->postJson(self::BASE.'/clients', ['name' => 'Seven Builders', 'code' => '7'])
            ->assertStatus(422)->assertJsonPath('message', 'A client code is two digits, like 07.');
        $this->withToken($token)->postJson(self::BASE.'/clients', ['name' => 'Seven Builders', 'code' => $taken['code']])
            ->assertStatus(422)->assertJsonPath('message', 'Another client already has the code '.$taken['code'].'.');
        $this->withToken($token)->postJson(self::BASE.'/clients', ['name' => strtoupper($taken['name']), 'code' => $free])
            ->assertStatus(422)->assertJsonPath('message', 'That client is already on the list.');
        $this->withToken($token)->postJson(self::BASE.'/clients', ['name' => '   ', 'code' => $free])->assertStatus(422);
    }

    // A client's code is in its project numbers: renamed freely, recoded only before any project.
    public function test_a_client_with_projects_keeps_its_code_and_its_place_on_the_list(): void
    {
        $token = $this->adminToken();
        $page = $this->withToken($token)->getJson(self::BASE)->assertOk();
        $used = collect($page->json('data.clients'))->firstWhere('projects', '>', 0);
        $free = (string) $page->json('data.nextClientCode');

        $this->withToken($token)->patchJson(self::BASE.'/clients/'.$used['id'], ['name' => $used['name'].' Inc', 'code' => $free])
            ->assertStatus(422)->assertJsonPath('message', "This client's code is already in its project numbers, so it cannot change.");
        $renamed = collect($this->withToken($token)->patchJson(self::BASE.'/clients/'.$used['id'], ['name' => $used['name'].' Inc', 'code' => $used['code']])
            ->assertOk()->json('data.clients'))->firstWhere('id', $used['id']);
        $this->assertSame($used['name'].' Inc', $renamed['name']);
        $this->withToken($token)->deleteJson(self::BASE.'/clients/'.$used['id'])
            ->assertStatus(422)->assertJsonPath('message', 'This client has projects, so it stays on the list.');

        // A client nobody has used yet can be recoded and removed.
        $new = collect($this->withToken($token)->postJson(self::BASE.'/clients', ['name' => 'Waipahu Works', 'code' => $free])->assertOk()->json('data.clients'))->firstWhere('code', $free);
        $recode = (string) $this->withToken($token)->getJson(self::BASE)->json('data.nextClientCode');
        $this->withToken($token)->patchJson(self::BASE.'/clients/'.$new['id'], ['name' => 'Waipahu Works', 'code' => $recode])->assertOk();
        $this->withToken($token)->deleteJson(self::BASE.'/clients/'.$new['id'])->assertOk();
        $this->withToken($token)->deleteJson(self::BASE.'/clients/'.$new['id'])->assertNotFound();
        $this->withToken($token)->patchJson(self::BASE.'/clients/'.$new['id'], ['name' => 'Gone', 'code' => $recode])->assertNotFound();
    }

    // A member can never hold the page, so a client write is refused before any validation.
    public function test_a_member_cannot_reach_the_clients_list(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $this->withToken($this->token($member))->postJson(self::BASE.'/clients', ['name' => 'Nope Ltd', 'code' => '98'])->assertForbidden();
    }

    // Reported live 2026-10-12: an admin whose Add projects is switched off (or limited) must
    // still open References, which reads the same project options the Add Project form does.
    public function test_the_page_reads_its_project_options_without_add_projects(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        DB::connection('portal')->table('access_toggles')->insert([
            'employee_id' => $admin->getKey(),
            'permission_key' => 'projects.create',
        ]);

        $this->withToken($this->token($admin))
            ->getJson('/api/development/portal/projects/options')
            ->assertOk();
    }
}
