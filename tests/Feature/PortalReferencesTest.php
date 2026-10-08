<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / Project Settings: the reference data a project is filed under. The page is a work in
 * progress, so its `projects.references` rule is Developer-mode-only for now: a developer in Developer
 * mode opens it, an admin in Default does not.
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

    // The page's rule is Developer mode only, so every working call signs in a developer and turns it on.
    private function developerToken(): string
    {
        $developer = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('is_developer', true)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();

        return $this->developerModeOn($this->token($developer));
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

    public function test_the_page_is_developer_mode_only(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
        $this->withToken($this->token($admin))->getJson(self::BASE)->assertForbidden();
    }

    public function test_the_list_returns_each_scope_with_its_department(): void
    {
        $scopes = $this->scopes($this->withToken($this->developerToken())->getJson(self::BASE)->assertOk());
        $first = $scopes[0] ?? null;

        $this->assertNotNull($first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('department', $first);
    }

    public function test_a_scope_is_added_edited_and_removed(): void
    {
        $token = $this->developerToken();

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
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $token = $this->developerToken();

        $this->add($token, ['code' => 'MIS-9002', 'name' => 'First', 'department' => 'MIS'])->assertOk();
        $this->add($token, ['code' => 'mis-9002', 'name' => 'Second', 'department' => 'MIS'])->assertStatus(422);
    }

    public function test_a_missing_scope_cannot_be_changed(): void
    {
        $token = $this->developerToken();

        $this->withToken($token)->patchJson(self::BASE.'/scopes/99999999', ['code' => 'X', 'name' => 'X', 'department' => 'MIS'])->assertNotFound();
        $this->withToken($token)->deleteJson(self::BASE.'/scopes/99999999')->assertNotFound();
    }

    public function test_a_project_type_is_added_edited_and_removed(): void
    {
        $token = $this->developerToken();

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
        $token = $this->developerToken();

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
        $departments = $this->withToken($this->developerToken())->getJson(self::BASE)->assertOk()->json('data.departments');
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
        $token = $this->developerToken();
        $page = $this->withToken($token)->getJson(self::BASE)->assertOk();
        $next = (string) $page->json('data.nextClientCode');
        $this->assertMatchesRegularExpression('/^\d{2}$/', $next);
        $this->assertNotContains($next, array_column($page->json('data.clients'), 'code'));
        $withProjects = collect($page->json('data.clients'))->firstWhere('projects', '>', 0);
        $this->assertNotNull($withProjects, 'No client with projects to check the count against.');

        $added = collect($this->withToken($token)->postJson(self::BASE.'/clients', ['name' => '  Kona   Builders ', 'code' => $next])
            ->assertOk()->json('data.clients'))->firstWhere('code', $next);
        $this->assertSame(['name' => 'Kona Builders', 'code' => $next, 'projects' => 0], collect($added)->only(['name', 'code', 'projects'])->all());

        // Add Project numbers the new client's first project with its code.
        $option = collect($this->withToken($token)->getJson('/api/development/portal/projects/options')->assertOk()->json('data.clients'))->firstWhere('id', $added['id']);
        $this->assertSame(now('Asia/Manila')->format('y').$next.'01', $option['nextNumber']);
    }

    public function test_a_client_needs_a_free_name_and_a_free_two_digit_code(): void
    {
        $token = $this->developerToken();
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
        $token = $this->developerToken();
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

    public function test_clients_are_developer_mode_only_like_the_rest_of_the_page(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $this->withToken($this->token($admin))->postJson(self::BASE.'/clients', ['name' => 'Nope Ltd', 'code' => '98'])->assertForbidden();
    }
}
