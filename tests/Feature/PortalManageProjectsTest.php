<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalSubmittedReportPresenter;
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
        // An admin approves their own department's projects by preset, so this one sits in the waiting project's.
        $this->admin->forceFill(['department' => 'Engineering'])->save();
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

    // Project Approver sits under Manage, and a power counts only under a held parent, as Fin and Audie hold both.
    private function approverDefault(string $reach): void
    {
        DB::connection('portal')->table('access_personal')->insert([
            ['employee_id' => $this->member->getKey(), 'permission_key' => 'manage', 'reach' => null],
            ['employee_id' => $this->member->getKey(), 'permission_key' => 'projects.manager', 'reach' => $reach],
        ]);
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
        $this->approverDefault('department');
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

    // Lead reach (Fin and Audie's default): only the waiting projects the approver leads, whatever the department.
    public function test_lead_reach_covers_only_the_projects_led(): void
    {
        $this->approverDefault('lead');
        $this->member->forceFill(['department' => 'Engineering'])->save();
        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update(['requested_by' => $this->admin->getKey()]);
        $token = $this->token($this->member);

        $this->assertNotContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
        $this->withToken($token)->patchJson(self::BASE.'/'.$this->projectId, ['decision' => 'approve'])->assertForbidden();

        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update(['project_lead_email' => strtoupper((string) $this->member->email)]);
        $this->assertContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
        $this->withToken($token)->patchJson(self::BASE.'/'.$this->projectId, ['decision' => 'approve'])->assertOk();
    }

    // Own reach: the waiting projects the approver is on or added, and no other.
    public function test_own_reach_covers_the_projects_on_or_added(): void
    {
        $other = (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_name' => 'Added Project 2020', 'department' => 'MIS', 'status' => 'Started',
            'project_creation_status' => 'Pending', 'requested_by' => $this->admin->getKey(), 'requested_at' => now(),
        ]);
        $token = $this->token($this->admin);
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.manager', 'reach' => 'own'])->assertOk();

        $ids = array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id');
        $this->assertContains((string) $other, $ids);
        $this->assertNotContains((string) $this->projectId, $ids);

        DB::connection('portal')->table('employees_projects')->insert(['project_id' => $this->projectId, 'employee_id' => $this->admin->getKey(), 'role_on_project' => 'member']);
        $this->assertContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
    }

    // An admin's Approver defaults to their own department (2026-10-07): another department's project is not theirs.
    public function test_an_admin_approves_only_their_own_department_by_default(): void
    {
        $this->admin->forceFill(['department' => 'MIS'])->save();
        Cache::flush();
        $token = $this->token($this->admin);

        $this->assertNotContains((string) $this->projectId, array_column($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'), 'id'));
        $this->withToken($token)->patchJson(self::BASE.'/'.$this->projectId, ['decision' => 'approve'])->assertForbidden();
        // Widening past the preset is not theirs to do on Access.
        $this->withToken($token)->patchJson('/api/development/portal/access/reach', ['key' => 'projects.manager', 'reach' => 'all'])->assertStatus(422);

        // Leading it brings it in, as department reach covers the projects led.
        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update(['project_lead_email' => $this->admin->email]);
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
    }

    // The reported ask: an administrator may change the approval after it was decided; a member approver may not.
    public function test_an_admin_changes_a_decided_approval_and_a_member_cannot(): void
    {
        $this->approverDefault('all');
        $this->decide($this->admin, ['decision' => 'approve'])->assertOk();
        $this->assertTrue($this->withToken($this->token($this->admin))->getJson(self::BASE.'?status=approved')->assertOk()->json('canChangeDecided'));
        $this->assertFalse($this->withToken($this->token($this->member))->getJson(self::BASE.'?status=approved')->assertOk()->json('canChangeDecided'));

        $this->decide($this->member, ['decision' => 'reject', 'remarks' => 'Late'])->assertStatus(422)
            ->assertJsonPath('message', 'This project is not waiting for approval.');
        $this->decide($this->admin, ['decision' => 'approve'])->assertStatus(422)
            ->assertJsonPath('message', 'The project is already approved.');
        $this->decide($this->admin, ['decision' => 'reject'])->assertStatus(422);

        $this->decide($this->admin, ['decision' => 'reject', 'remarks' => 'Wrong client'])->assertOk()
            ->assertJsonPath('data.creationStatus', 'rejected');
        $this->assertSame('Project Creation Rejected', $this->latestTrail());

        // Back to waiting clears who decided, and the requester hears of it.
        $this->decide($this->admin, ['decision' => 'pending'])->assertOk()
            ->assertJsonPath('data.creationStatus', 'pending');
        $row = DB::connection('portal')->table('projects')->where('id', $this->projectId)->first();
        $this->assertSame(['Pending', null, null], [$row->project_creation_status, $row->project_creation_approver, $row->project_approver_email]);
        $this->assertSame('Project Creation Reopened', $this->latestTrail());
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $this->member->getKey())->where('title', 'Project back to waiting')->exists());
        $this->decide($this->admin, ['decision' => 'pending'])->assertStatus(422)
            ->assertJsonPath('message', 'The project is already waiting.');
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
        // A rejected project may be missing from the requester's board, so the notice opens Created Projects.
        $this->assertSame('/projects/created-projects', DB::connection('portal')->table('notifications')
            ->where('employee_id', $this->member->getKey())->where('type', 'projects.rejected')->orderByDesc('id')->value('link_path'));
    }

    // Created Projects lists only what the person added, by approval, with the project's own status.
    public function test_my_projects_lists_only_what_i_added(): void
    {
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $this->member->getKey(), 'permission_key' => 'projects.created', 'reach' => null]);
        $othersId = (int) DB::connection('portal')->table('projects')->insertGetId([
            'project_name' => 'Someone Else 2020',
            'department' => 'Engineering',
            'status' => 'On Hold',
            'project_creation_status' => 'Pending',
            'requested_by' => $this->admin->getKey(),
            'requested_at' => now(),
        ]);
        DB::connection('portal')->table('employees_projects')->insert(['project_id' => $this->projectId, 'employee_id' => $this->member->getKey(), 'role_on_project' => 'member']);
        $token = $this->token($this->member);

        $response = $this->withToken($token)->getJson('/api/development/portal/projects/mine')->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains((string) $this->projectId, $ids);
        $this->assertNotContains((string) $othersId, $ids);
        $item = collect($response->json('data'))->firstWhere('id', (string) $this->projectId);
        $this->assertSame('pending', $item['creationStatus']);
        $this->assertSame('ongoing', $item['projectStatus']);
        $this->assertContains(PortalSubmittedReportPresenter::memberName($this->member->first_name, $this->member->last_name), $item['memberNames']);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.pending'));

        // Every approval comes in one read; the page splits them into its tabs.
        $this->decide($this->admin, ['decision' => 'approve'])->assertOk();
        $after = $this->withToken($token)->getJson('/api/development/portal/projects/mine')->assertOk();
        $this->assertSame('approved', collect($after->json('data'))->firstWhere('id', (string) $this->projectId)['creationStatus'] ?? null);
        $this->assertGreaterThanOrEqual(1, $after->json('counts.approved'));
    }

    public function test_my_projects_needs_the_created_projects_rule(): void
    {
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $this->member->getKey(), 'permission_key' => 'projects.created']);
        $this->withToken($this->token($this->member))->getJson('/api/development/portal/projects/mine')->assertForbidden();
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

    // The details page opens one project with every field the Add / Edit Project form carries.
    public function test_a_project_opens_with_every_field_the_form_carries(): void
    {
        $this->approverDefault('all');
        $token = $this->token($this->member);
        DB::connection('portal')->table('projects')->where('id', $this->projectId)->update([
            'project_number' => '900001',
            'type_of_job' => 'Scan-to-BIM',
            'area_sqft' => 1200,
            'project_lead_email' => strtolower((string) $this->admin->email),
            'lod' => 'LOD 300',
            'ar_lod' => 'LOD 350',
            'forma_link' => 'https://forma.example/x',
        ]);
        DB::connection('portal')->table('employees_projects')->insert([
            ['employee_id' => $this->admin->getKey(), 'project_id' => $this->projectId, 'role_on_project' => 'member'],
            ['employee_id' => $this->member->getKey(), 'project_id' => $this->projectId, 'role_on_project' => 'support'],
        ]);

        $data = $this->withToken($token)
            ->getJson('/api/development/portal/manage/projects/'.$this->projectId)
            ->assertOk()
            ->json('data');

        $this->assertSame('Waiting Project 2020', $data['name']);
        $this->assertSame('900001', $data['projectNumber']);
        $this->assertSame('Engineering', $data['department']);
        $this->assertSame('Scan-to-BIM', $data['typeLabel']);
        // DECIMAL comes back with its scale, which the portal formats for display.
        $this->assertSame('1200.00', $data['area']);
        $this->assertSame('LOD 300', $data['levels']['lod']);
        $this->assertSame('LOD 350', $data['levels']['architectural']);
        $this->assertSame('https://forma.example/x', $data['links']['forma']);
        $this->assertSame([$this->member->first_name.' '.$this->member->last_name], $data['supportMemberNames']);
        $this->assertCount(1, $data['memberNames']);
        $this->assertNotNull($data['requestedByName']);
    }

    // Only an approver who covers the project may open it; anybody else is refused at the rule.
    public function test_a_project_outside_the_reach_is_refused(): void
    {
        $this->withToken($this->token($this->member))
            ->getJson('/api/development/portal/manage/projects/'.$this->projectId)
            ->assertForbidden();
    }

    public function test_a_project_that_is_not_there_is_not_found(): void
    {
        $this->approverDefault('all');

        $this->withToken($this->token($this->member))
            ->getJson('/api/development/portal/manage/projects/99999999')
            ->assertNotFound();
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
