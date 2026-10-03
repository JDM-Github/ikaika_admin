<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / Project Settings: the scopes (activity codes) a department may report on. The page is
 * gated by Project Manager until it has a power of its own; adding, editing and removing re-read the
 * same list for everyone.
 */
class PortalProjectSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/project-settings';

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

    public function test_a_member_without_project_manager_is_refused(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
    }

    public function test_the_list_returns_each_scope_with_its_department(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $scopes = $this->scopes($this->withToken($this->token($admin))->getJson(self::BASE)->assertOk());
        $first = $scopes[0] ?? null;

        $this->assertNotNull($first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('department', $first);
    }

    public function test_a_scope_is_added_edited_and_removed(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);

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
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);

        $this->add($token, ['code' => 'MIS-9002', 'name' => 'First', 'department' => 'MIS'])->assertOk();
        $this->add($token, ['code' => 'mis-9002', 'name' => 'Second', 'department' => 'MIS'])->assertStatus(422);
    }

    public function test_a_missing_scope_cannot_be_changed(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);

        $this->withToken($token)->patchJson(self::BASE.'/scopes/99999999', ['code' => 'X', 'name' => 'X', 'department' => 'MIS'])->assertNotFound();
        $this->withToken($token)->deleteJson(self::BASE.'/scopes/99999999')->assertNotFound();
    }
}
