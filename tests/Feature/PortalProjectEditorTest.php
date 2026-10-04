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
 * Adding a project follows Add projects (projects.create); editing it and saving its scopes follow Edit
 * projects and Manage scopes at a reach covering it, a member holding both only where given, at own for the projects
 * they lead. Every write lands in SQL with a ledger action and a log row, and View Projects reads the saved scopes first.
 */
class PortalProjectEditorTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/projects';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        DB::connection('portal')->table('access_toggles')->where('permission_key', 'like', 'projects%')->delete();
    }

    private function employee(string $role, int $skip = 0): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw('LOWER(COALESCE(role, \'\')) = ?', [$role])
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->skip($skip)
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    /**
     * A member's project powers are given now, not presets: grant the keys (key => reach) as an admin.
     *
     * @param  array<string, string|null>  $reaches
     */
    private function grant(Employee $target, array $reaches, ?Employee $giver = null): void
    {
        $giver ??= Employee::query()
            ->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('id')
            ->firstOrFail();
        foreach ($reaches as $key => $reach) {
            DB::connection('portal')->table('access_grants')->insertOrIgnore([
                'employee_id' => $target->id,
                'permission_key' => $key,
                'reach' => $reach,
                'granted_by' => $giver->id,
                'as_developer' => 0,
            ]);
        }
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    /**
     * @return list<string>
     */
    private function scopeCodes(): array
    {
        $process = (string) DB::connection('portal')->table('project_scope_t1_processes')->orderBy('wbs_code')->value('wbs_code');
        $task = (string) DB::connection('portal')->table('project_scope_t4_tasks')->where('wbs_code', 'not like', '%-')->orderBy('wbs_code')->value('wbs_code');

        return [trim($process), trim($task)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $lead = $this->employee('user');
        // A member leads only when given Project Lead; the form refuses a lead who cannot lead.
        $this->grant($lead, ['projects.lead' => 'department']);

        return array_replace([
            'name' => 'Editor Test Project 2020',
            'leadId' => $lead->id,
            'clientId' => (int) DB::connection('portal')->table('clients')->orderBy('id')->value('id'),
            'department' => 'Engineering',
            'type' => 'Scan-to-BIM',
            'area' => 1250,
            'status' => 'on-hold',
            'memberIds' => [$this->employee('user', 1)->id],
            'supportMemberIds' => [$this->employee('user', 2)->id],
            'levels' => ['structural' => '300', 'architectural' => 'N/A', 'electrical' => null],
            'links' => ['forma' => 'https://autodeskforma.com/project', 'google-drive' => '/Shared drives/Projects/Editor'],
            'scopeCodes' => $this->scopeCodes(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function create(string $token, array $payload): TestResponse
    {
        return $this->withToken($token)->postJson(self::BASE, $payload);
    }

    private function liveProjectId(): int
    {
        $id = (int) DB::connection('portal')->table('projects')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_lead_email' => 'nobody@ikaika.test']);

        return $id;
    }

    public function test_an_admin_adds_an_approved_project_with_its_people_client_and_scopes(): void
    {
        $admin = $this->employee('admin');
        $payload = $this->payload();

        $response = $this->create($this->token($admin), $payload)->assertCreated();
        $id = (int) $response->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Editor Test Project 2020', $row->project_name);
        $this->assertSame('Approved', $row->project_creation_status);
        $this->assertSame($admin->email, $row->project_approver_email);
        $this->assertSame('On Hold', $row->status);
        $this->assertSame('LOD 300', $row->st_lod);
        $this->assertSame('N/A', $row->ar_lod);
        $this->assertNull($row->el_lod);
        $this->assertSame('/Shared drives/Projects/Editor', $row->google_drive_folder_path);
        $this->assertSame(Employee::query()->findOrFail($payload['leadId'])->email, $row->project_lead_email);
        $this->assertSame(['member', 'support'], DB::connection('portal')->table('employees_projects')->where('project_id', $id)->orderBy('role_on_project')->pluck('role_on_project')->all());
        $this->assertSame($payload['clientId'], (int) DB::connection('portal')->table('projects_clients')->where('project_id', $id)->value('client_id'));
        $this->assertEqualsCanonicalizing($payload['scopeCodes'], DB::connection('portal')->table('projects_wbs_scopes')->where('project_id', $id)->pluck('wbs_code')->all());

        $response->assertJsonPath('data.status', 'on-hold')
            ->assertJsonPath('data.levels.structural', '300')
            ->assertJsonPath('data.leadId', (string) $payload['leadId']);
        $this->assertTrue(DB::connection('core')->table('actions')->where('database_target', 'portal.projects')->where('record_id', (string) $id)->where('action_type', CoreActionType::ADD)->exists());
        $this->assertSame('{UserName|You} added the project Editor Test Project 2020', DB::connection('portal')->table('logs')->where('resource', 'projects')->where('record_id', (string) $id)->value('message'));
    }

    /**
     * @return list<array{action_name: string, created_by: string, remarks: ?string}>
     */
    private function trail(int $projectId): array
    {
        return DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $projectId)
            ->orderBy('project_action_history.id')
            ->get(['project_action_history.action_name', 'project_action_history.created_by', 'project_action_history.remarks'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    // Each save adds a Trail history row in Airtable's own words; an edit says what changed, a no-op edit says nothing.
    public function test_every_save_writes_the_trail_history(): void
    {
        $admin = $this->employee('admin');
        $token = $this->token($admin);
        $payload = $this->payload(['status' => 'ongoing']);
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Trail Renamed 2020', 'status' => 'on-hold', 'area' => 1300])->assertOk();
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Trail Renamed 2020', 'status' => 'on-hold', 'area' => 1300])->assertOk();
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[1]]])->assertOk();

        $name = trim($admin->first_name.' '.$admin->last_name);
        $this->assertSame([
            ['action_name' => 'Project Creation', 'created_by' => $name, 'remarks' => null],
            ['action_name' => 'Project Edited', 'created_by' => $name, 'remarks' => 'Name: Editor Test Project 2020 to Trail Renamed 2020; Status: Ongoing to On Hold; Area: 1,250 to 1,300 sq ft'],
            ['action_name' => 'Scopes Changed', 'created_by' => $name, 'remarks' => '1 scope'],
        ], $this->trail($id));

        $board = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame('Scopes Changed: 1 scope', $board['trail'][0]['summary']);
    }

    // Given Add projects anywhere, a member's project still waits where no Project Manager covers its department.
    public function test_a_members_project_waits_for_a_project_manager(): void
    {
        $member = $this->employee('user', 3);
        $admin = $this->employee('admin');
        $this->grant($member, ['projects.create' => 'all']);
        $this->token($admin);

        $response = $this->create($this->token($member), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.creationStatus', 'pending');
        $id = (int) $response->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame('Pending', $row->project_creation_status);
        $this->assertNull($row->project_creation_approver);
        $this->assertSame((int) $member->getKey(), (int) $row->requested_by);
        $this->assertNotNull($row->requested_at);
        // Admins hold Project Manager for every department, so they are told.
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $admin->getKey())->where('type', 'projects.requested')->where('link_path', '/manage/manage-projects')->exists());
        $this->assertFalse(DB::connection('portal')->table('notifications')->where('employee_id', $member->getKey())->where('type', 'projects.requested')->exists());
    }

    public function test_without_add_projects_nothing_is_added(): void
    {
        $member = $this->employee('user', 3);
        $before = DB::connection('portal')->table('projects')->count();

        $this->create($this->token($member), $this->payload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Adding a project needs the Add projects power.');
        $this->assertSame($before, DB::connection('portal')->table('projects')->count());
    }

    // Whoever asked may edit their waiting project, and saving a rejected one sends it again.
    public function test_the_requester_edits_and_resends_a_rejected_project(): void
    {
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->grant($member, ['projects.create' => 'all']);
        $payload = $this->payload();
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Waiting Edit 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'pending');

        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_creation_status' => 'Rejected', 'project_creation_approver_remarks' => 'Wrong client']);
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.creationRemarks', 'Wrong client');
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Resent 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'pending')
            ->assertJsonPath('data.creationRemarks', null);

        $this->assertSame('Project Creation Resubmission', DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $id)->orderByDesc('project_action_history.id')->value('project_action_history.action_name'));

        // Approved, it is no longer theirs to edit.
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_creation_status' => 'Approved']);
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)->assertForbidden();
    }

    public function test_adding_refuses_what_is_not_on_the_lists(): void
    {
        $token = $this->token($this->employee('admin'));
        $inactive = (int) DB::connection('portal')->table('employees')->whereRaw("LOWER(COALESCE(status, '')) != 'active'")->value('id');

        $this->create($token, $this->payload(['scopeCodes' => ['999-99']]))->assertStatus(422)->assertJsonPath('message', 'Choose scopes from the list.');
        $this->create($token, $this->payload(['links' => ['forma' => 'javascript:alert(1)']]))->assertStatus(422)->assertJsonPath('message', 'Each link must start with http:// or https://.');
        $this->create($token, $this->payload(['memberIds' => [$inactive]]))->assertStatus(422)->assertJsonPath('message', 'Choose members from the list.');
        $this->create($token, $this->payload(['department' => 'Nowhere']))->assertStatus(422)->assertJsonPath('message', 'Choose a department from the list.');
        $this->create($token, $this->payload(['status' => 'paused']))->assertStatus(422);
        $this->create($token, $this->payload(['name' => '   ']))->assertStatus(422);
    }

    // The form owns member and support rows only: the pm rows Airtable set stay, and so does a member who has left.
    public function test_editing_replaces_members_and_support_and_keeps_pm_rows(): void
    {
        $id = $this->liveProjectId();
        $pm = $this->employee('user', 4);
        $left = (int) DB::connection('portal')->table('employees')->whereRaw("LOWER(COALESCE(status, '')) != 'active'")->value('id');
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->delete();
        DB::connection('portal')->table('employees_projects')->insert([
            ['employee_id' => $pm->id, 'project_id' => $id, 'role_on_project' => 'pm'],
            ['employee_id' => $left, 'project_id' => $id, 'role_on_project' => 'member'],
        ]);
        $payload = $this->payload(['name' => 'Renamed 2020', 'status' => 'completed', 'memberIds' => [$left, $this->employee('user', 1)->id]]);
        unset($payload['scopeCodes']);

        $this->withToken($this->token($this->employee('admin')))->patchJson(self::BASE.'/'.$id, $payload)
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed 2020')
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame('Done', DB::connection('portal')->table('projects')->where('id', $id)->value('status'));
        $roles = DB::connection('portal')->table('employees_projects')->where('project_id', $id)->get()
            ->map(static fn (object $row): string => $row->role_on_project.':'.$row->employee_id)->sort()->values()->all();
        $this->assertSame(collect(['member:'.$left, 'member:'.$payload['memberIds'][1], 'pm:'.$pm->id, 'support:'.$payload['supportMemberIds'][0]])->sort()->values()->all(), $roles);
        $this->assertTrue(DB::connection('core')->table('actions')->where('database_target', 'portal.projects')->where('record_id', (string) $id)->where('action_type', CoreActionType::EDIT)->exists());
    }

    public function test_a_member_edits_only_a_project_they_lead(): void
    {
        $id = $this->liveProjectId();
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->grant($member, ['projects.edit' => 'lead']);
        $payload = $this->payload();
        unset($payload['scopeCodes']);

        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertForbidden();
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your Edit projects power does not reach this project.');

        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_lead_email' => strtoupper((string) $member->email)]);
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.id', (string) $id);
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)->assertOk();
    }

    // "Only your own" is the projects a member is on; "the projects you lead" is the led ones, and only those.
    public function test_own_reaches_the_projects_you_are_on_and_lead_the_projects_you_lead(): void
    {
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $portal = DB::connection('portal');
        $on = $this->liveProjectId();
        $led = (int) $portal->table('projects')->whereNull('deleted_at')->where('id', '!=', $on)->orderByDesc('id')->value('id');
        $portal->table('projects')->whereIn('id', [$on, $led])->update(['project_creation_status' => 'Approved', 'requested_by' => null]);
        $portal->table('projects')->where('id', $on)->update(['project_lead_email' => 'nobody@ikaika.test']);
        $portal->table('projects')->where('id', $led)->update(['project_lead_email' => $member->email]);
        $portal->table('employees_projects')->where('employee_id', $member->getKey())->whereIn('project_id', [$on, $led])->delete();
        $portal->table('employees_projects')->insert(['employee_id' => $member->getKey(), 'project_id' => $on, 'role_on_project' => 'member']);

        // On it but not leading: "own" reaches it, "lead" does not.
        $this->grant($member, ['projects.edit' => 'own']);
        $this->withToken($token)->getJson(self::BASE.'/'.$on)->assertOk();
        $this->withToken($token)->getJson(self::BASE.'/'.$led)->assertForbidden();

        // Leading it but not on it: "lead" reaches it, "own" does not.
        $portal->table('access_grants')->where('employee_id', $member->getKey())->where('permission_key', 'projects.edit')->update(['reach' => 'lead']);
        $this->withToken($token)->getJson(self::BASE.'/'.$on)->assertForbidden();
        $this->withToken($token)->getJson(self::BASE.'/'.$led)->assertOk();
    }

    // Switched off, Manage scopes is gone even for an admin who leads nothing, and Edit projects stays.
    public function test_an_admin_without_manage_scopes_is_refused(): void
    {
        $id = $this->liveProjectId();
        $admin = $this->employee('admin');
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.scopes']);
        $token = $this->token($admin);

        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => $this->scopeCodes()])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your Manage scopes power does not reach this project.');
        $this->assertFalse(DB::connection('portal')->table('projects_wbs_scopes')->where('project_id', $id)->exists());
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk();
    }

    // Department reach covers that department's projects and the ones they lead, nothing else.
    public function test_department_reach_edits_only_that_department(): void
    {
        $id = $this->liveProjectId();
        $member = $this->employee('user', 3);
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'projects.edit', 'reach' => 'department']);
        DB::connection('portal')->table('projects')->where('id', $id)->update(['department' => 'Engineering', 'project_lead_email' => null]);

        $member->forceFill(['department' => 'MIS'])->save();
        $this->withToken($this->token($member))->getJson(self::BASE.'/'.$id)->assertForbidden();

        $member->forceFill(['department' => 'engineering'])->save();
        $this->withToken($this->token($member))->getJson(self::BASE.'/'.$id)->assertOk();
    }

    // Leading a project is a power like any other: a manager's limit or the lead's own switch takes it away.
    public function test_a_lead_loses_their_project_to_a_limit_or_their_own_switch(): void
    {
        $id = $this->liveProjectId();
        $member = $this->employee('user', 3);
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_lead_email' => $member->email]);
        // A personal default is where they start, so a manager's switch-off records a limit, not a gift to take back.
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'projects.edit', 'reach' => 'lead']);
        $this->withToken($this->token($member))->getJson(self::BASE.'/'.$id)->assertOk();

        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'projects.edit']);
        $this->withToken($this->token($member))->getJson(self::BASE.'/'.$id)->assertForbidden();
        DB::connection('portal')->table('access_toggles')->delete();

        $admin = $this->employee('admin');
        $member->forceFill(['department' => $admin->department])->save();
        $this->withToken($this->token($admin))->patchJson('/api/development/portal/manage/access/'.$member->getKey(), ['key' => 'projects.edit', 'action' => 'off'])->assertOk();
        $this->assertTrue(DB::connection('portal')->table('access_limits')->where('employee_id', $member->getKey())->where('permission_key', 'projects.edit')->exists());
        $this->withToken($this->token($member))->getJson(self::BASE.'/'.$id)->assertForbidden();
    }

    public function test_saving_scopes_replaces_the_set_and_view_projects_reads_it(): void
    {
        $id = $this->liveProjectId();
        [$process, $task] = $this->scopeCodes();
        $token = $this->token($this->employee('admin'));

        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$process, $task]])->assertOk();
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$task, $task]])
            ->assertOk()
            ->assertJsonPath('data.scopeCodes', [$task]);

        $project = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame([$task], $project['scopeCodes']);
        $this->assertSame(1, $project['scopeSummary']['total']);
        $this->assertSame('{UserName|You} set 1 scope on the project '.DB::connection('portal')->table('projects')->where('id', $id)->value('project_name'), DB::connection('portal')->table('logs')->where('resource', 'projects')->where('record_id', (string) $id)->orderByDesc('id')->value('message'));
    }

    public function test_the_form_options_are_for_those_who_add_edit_or_lead(): void
    {
        $options = $this->withToken($this->token($this->employee('admin')))->getJson(self::BASE.'/options')
            ->assertOk()
            ->assertJsonPath('data.departments', fn (array $departments): bool => in_array('Engineering', $departments, true))
            ->assertJsonStructure(['data' => ['employees' => [['id', 'name', 'canLead', 'department']], 'clients' => [['id', 'name']], 'types', 'typesByDepartment']]);

        // An executive is never offered as a lead, member or support member.
        $executiveId = (int) DB::connection('portal')->table('employees')->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")->value('id');
        if ($executiveId !== 0) {
            $this->assertNotContains((string) $executiveId, array_column($options->json('data.employees'), 'id'));
        }

        // A plain member holds none of Add/Edit/Project Lead, so the form is closed to them; given one, it opens.
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->withToken($token)->getJson(self::BASE.'/options')->assertForbidden();

        $this->grant($member, ['projects.create' => 'all']);
        Cache::flush();
        $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();

        DB::connection('portal')->table('projects')->where('id', $this->liveProjectId())->update(['project_lead_email' => $member->email]);
        $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();
    }

    // The buckets projects were once filed under stay on the form, alongside the staffed departments.
    public function test_the_form_offers_the_project_buckets_again(): void
    {
        $departments = $this->withToken($this->token($this->employee('admin')))
            ->getJson(self::BASE.'/options')->assertOk()->json('data.departments');

        $this->assertContains('Engineering', $departments);
        $this->assertContains('General', $departments);
        $this->assertContains('Admin', $departments);
    }

    // Add projects reaches departments: narrowed to their own, a member may only start their department's projects.
    public function test_add_projects_reach_limits_the_departments(): void
    {
        $member = $this->employee('user', 3);
        $member->forceFill(['department' => 'MIS'])->save();
        $token = $this->token($member);
        $this->grant($member, ['projects.create' => 'all']);
        $this->assertContains('Engineering', $this->withToken($token)->getJson(self::BASE.'/options')->assertOk()->json('data.addDepartments'));

        DB::connection('portal')->table('access_self_reach')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'projects.create', 'reach' => 'department']);
        $options = $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();
        $this->assertSame(['MIS'], $options->json('data.addDepartments'));
        $this->assertContains('Engineering', $options->json('data.departments'));
        $this->create($token, $this->payload())->assertForbidden()->assertJsonPath('message', 'Your Add projects power does not reach that department.');
    }

    // Project Lead says who may lead where: given at department, only their own department's project;
    // the two managers (lead at every department, a personal default) lead any.
    public function test_the_lead_must_hold_project_lead_for_the_department(): void
    {
        $token = $this->token($this->employee('admin'));
        $lead = $this->employee('user');
        $manager = Employee::query()->where('id_no', '220302-0003')->firstOrFail();

        $lead->forceFill(['department' => 'MIS'])->save();
        $this->grant($lead, ['projects.lead' => 'department']);
        $people = collect($this->withToken($token)->getJson(self::BASE.'/options')->assertOk()->json('data.employees'))->keyBy('id');
        $this->assertSame(['canLead' => true, 'leadsAnywhere' => false, 'leadDepartment' => 'MIS'], collect($people[(string) $lead->id])->only(['canLead', 'leadsAnywhere', 'leadDepartment'])->all());
        $this->assertTrue($people[(string) $manager->id]['leadsAnywhere']);
        $this->create($token, $this->payload())->assertStatus(422)->assertJsonPath('message', 'That person cannot lead a project of this department. Choose a lead from the list.');
        $this->create($token, $this->payload(['leadId' => $manager->id]))->assertCreated();

        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $lead->getKey(), 'permission_key' => 'projects.lead']);
        $people = collect($this->withToken($token)->getJson(self::BASE.'/options')->assertOk()->json('data.employees'))->keyBy('id');
        $this->assertFalse($people[(string) $lead->id]['canLead']);
    }

    // Add without approval: a project its holder adds is approved at once, even where no Project Manager covers them.
    public function test_add_without_approval_skips_the_wait(): void
    {
        $member = $this->employee('user', 3);
        $member->forceFill(['department' => 'MIS'])->save();
        $this->grant($member, ['projects.create' => 'all', 'projects.create.direct' => null]);

        $id = (int) $this->create($this->token($member), $this->payload())->assertCreated()->json('data.id');

        $this->assertSame('Approved', DB::connection('portal')->table('projects')->where('id', $id)->value('project_creation_status'));
    }

    // Level of detail, area, Forma and panoramic are Engineering's: another department's project has them cleared.
    public function test_a_non_engineering_project_drops_the_engineering_fields(): void
    {
        $admin = $this->employee('admin');
        $payload = $this->payload([
            'department' => 'MIS',
            'leadId' => $admin->id,
            'scopeCodes' => [],
            'links' => ['forma' => 'https://autodeskforma.com/p', 'panoramic' => 'https://pano.example.com', 'ms-teams' => 'https://teams.microsoft.com/l/1'],
        ]);

        $id = (int) $this->create($this->token($admin), $payload)->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame([null, null, null, null], [$row->st_lod, $row->area_sqft, $row->forma_link, $row->panoramic_link]);
        $this->assertSame('https://teams.microsoft.com/l/1', $row->msteams_project_link);
    }

    // A project that is not Engineering's scopes from its department's and General's activity codes, never the WBS tree.
    public function test_a_non_engineering_project_scopes_from_its_activity_codes(): void
    {
        $portal = DB::connection('portal');
        $portal->table('activity_codes')->insert([
            ['id_no' => '9901', 'name' => '9901- TEST MIS WORK', 'department' => 'MIS'],
            ['id_no' => '9902', 'name' => 'Test drafting', 'department' => 'Engineering'],
            ['id_no' => '9903', 'name' => 'Test meeting', 'department' => 'General'],
        ]);
        $id = $this->liveProjectId();
        $token = $this->token($this->employee('admin'));
        $portal->table('projects')->where('id', $id)->update(['department' => 'MIS']);

        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => ['9901', '9903']])->assertOk();
        $this->assertSame(['9901', '9903'], $portal->table('projects_wbs_scopes')->where('project_id', $id)->orderBy('wbs_code')->pluck('wbs_code')->all());
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => ['9902']])->assertStatus(422);
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => $this->scopeCodes()])->assertStatus(422);

        $board = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $offered = array_column($board['scopeOptions'], 'code');
        $this->assertContains('9901', $offered);
        $this->assertContains('9903', $offered);
        $this->assertNotContains('9902', $offered);
        $this->assertContains(['code' => '9901', 'name' => 'TEST MIS WORK', 'department' => 'MIS'], $this->withToken($token)->getJson(self::BASE.'/options')->json('data.activityCodes'));

        $portal->table('projects')->where('id', $id)->update(['department' => 'Engineering']);
        Cache::flush();
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => ['9901']])->assertStatus(422);
        $this->assertSame([], collect($this->withToken($token)->getJson(self::BASE)->json('data'))->firstWhere('id', (string) $id)['scopeOptions']);
    }

    public function test_writes_are_held_to_twenty_a_minute(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        Cache::flush();
        $id = $this->liveProjectId();
        $token = $this->token($this->employee('admin'));

        foreach (range(1, 20) as $attempt) {
            $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => []])->assertOk();
        }
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => []])->assertStatus(429);
    }
}
