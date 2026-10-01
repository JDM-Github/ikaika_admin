<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / Projects follows Project Manager (projects.manager): the projects waiting for the
 * departments its reach covers, approved or rejected with a reason, each decision written to the
 * project, the ledger, the Trail history and the log, and told to whoever asked.
 */
class PortalManageProjectsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/projects';

    private Employee $member;

    private Employee $admin;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        $this->member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $this->admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $this->projectId = (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_name' => 'Waiting Project 2020',
            'department' => 'Engineering',
            'status' => 'Started',
            'project_creation_status' => 'Pending',
            'requested_by' => $this->member->getKey(),
            'requested_at' => now(),
        ]);
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
     * @param  array<string, mixed>  $body
     */
    private function decide(Employee $actor, array $body): TestResponse
    {
        return $this->withToken($this->token($actor))->patchJson(self::BASE.'/'.$this->projectId, $body);
    }

    public function test_a_project_manager_lists_what_waits_with_who_asked(): void
    {
        $response = $this->withToken($this->token($this->admin))->getJson(self::BASE)->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', (string) $this->projectId);
        $this->assertNotNull($item);
        $this->assertSame('pending', $item['creationStatus']);
        $this->assertSame(trim($this->member->first_name.' '.$this->member->last_name), $item['requestedByName']);
        $this->assertNotNull($item['requestedAt']);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.pending'));

        $approved = $this->withToken($this->token($this->admin))->getJson(self::BASE.'?status=approved')->assertOk()->json('data');
        $this->assertNotContains((string) $this->projectId, array_column($approved, 'id'));
    }

    // Department reach covers only the manager's own department's projects.
    public function test_department_reach_covers_only_that_department(): void
    {
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->member->getKey(), 'permission_key' => 'projects.manager', 'reach' => 'department']);
        $this->member->forceFill(['department' => 'MIS'])->save();
        $token = $this->token($this->member);

        $ids = array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id');
        $this->assertNotContains((string) $this->projectId, $ids);
        $this->withToken($token)->patchJson(self::BASE.'/'.$this->projectId, ['decision' => 'approve'])->assertForbidden();

        $this->member->forceFill(['department' => 'engineering'])->save();
        Cache::flush();
        $ids = array_column($this->withToken($this->token($this->member))->getJson(self::BASE)->assertOk()->json('data'), 'id');
        $this->assertContains((string) $this->projectId, $ids);
    }

    // An admin who narrows Project Manager to their department on Access loses other departments until they widen it back.
    public function test_an_admin_narrowed_to_department_covers_only_it(): void
    {
        $this->admin->forceFill(['department' => 'MIS'])->save();
        $token = $this->token($this->admin);

        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.manager', 'reach' => 'department'])->assertOk();
        $this->assertNotContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
        $this->withToken($token)->patchJson(self::BASE.'/'.$this->projectId, ['decision' => 'approve'])->assertForbidden();

        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.manager', 'reach' => 'all'])->assertOk();
        $this->assertSame(0, DB::connection('portal')->table('access_self_reach')->where('employee_id', $this->admin->getKey())->count());
        $this->assertContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
    }

    public function test_approving_writes_the_project_trail_ledger_and_tells_the_requester(): void
    {
        $this->decide($this->admin, ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'approved')
            ->assertJsonPath('data.approverName', trim($this->admin->first_name.' '.$this->admin->last_name));

        $row = DB::connection('portal')->table('projects')->where('id', $this->projectId)->first();
        $this->assertSame('Approved', $row->project_creation_status);
        $this->assertSame($this->admin->email, $row->project_approver_email);
        $this->assertSame('Project Creation Approved', $this->latestTrail());
        $this->assertTrue(DB::connection('core')->table('actions')->where('record_id', (string) $this->projectId)->where('action_type', CoreActionType::EDIT)->exists());
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $this->member->getKey())->where('type', 'projects.approved')->where('link_path', '/projects/projects')->exists());

        // Decided once: it is no longer waiting.
        $this->decide($this->admin, ['decision' => 'reject', 'remarks' => 'Late'])->assertStatus(422)
            ->assertJsonPath('message', 'This project is not waiting for approval.');
    }

    public function test_rejecting_needs_a_reason_and_keeps_it(): void
    {
        $this->decide($this->admin, ['decision' => 'reject'])->assertStatus(422)
            ->assertJsonPath('message', 'Say why the project is rejected, so whoever asked can fix it.');

        $this->decide($this->admin, ['decision' => 'reject', 'remarks' => 'Pick the right client'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'rejected')
            ->assertJsonPath('data.remarks', 'Pick the right client');

        $this->assertSame('Pick the right client', DB::connection('portal')->table('projects')->where('id', $this->projectId)->value('project_creation_approver_remarks'));
        $this->assertSame('Project Creation Rejected', $this->latestTrail());
        $this->assertStringContainsString('Pick the right client', (string) DB::connection('portal')->table('notifications')
            ->where('employee_id', $this->member->getKey())->where('type', 'projects.rejected')->value('message'));
    }

    public function test_without_project_manager_the_page_is_refused(): void
    {
        $this->withToken($this->token($this->member))->getJson(self::BASE)->assertForbidden();
        $this->decide($this->member, ['decision' => 'approve'])->assertForbidden();
        $this->decide($this->admin, ['decision' => 'maybe'])->assertStatus(422);
        $this->withToken($this->token($this->admin))->patchJson(self::BASE.'/999999999', ['decision' => 'approve'])->assertNotFound();
    }

    // The board marks a waiting project; it stays usable meanwhile, as Airtable's always were.
    public function test_the_board_marks_a_waiting_project(): void
    {
        $board = collect($this->withToken($this->token($this->admin))->getJson('/api/development/portal/projects')->assertOk()->json('data'));

        $this->assertSame('pending', $board->firstWhere('id', (string) $this->projectId)['creationStatus'] ?? null);
    }

    private function latestTrail(): ?string
    {
        return DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $this->projectId)
            ->orderByDesc('project_action_history.id')
            ->value('project_action_history.action_name');
    }
}
