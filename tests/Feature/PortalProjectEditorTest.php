<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalActivityScopes;
use App\Support\Portal\PortalEmployeePresenter;
use App\Support\Portal\PortalProjectActivities;
use App\Support\Portal\PortalProjectPowers;
use App\Support\Portal\PortalReportProjects;
use App\Support\Portal\PortalViewProjectPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // A fresh name each time: no two live projects may share one.
        return array_replace([
            'name' => 'Editor Test Project '.Str::random(8),
            'leadId' => $lead->id,
            'clientId' => (int) DB::connection('portal')->table('clients')->orderBy('id')->value('id'),
            'department' => 'Engineering',
            'type' => 'Scan-to-BIM',
            'area' => 1250,
            'status' => 'ongoing',
            'memberIds' => [$this->employee('user', 1)->id],
            'supportMemberIds' => [$this->employee('user', 2)->id],
            // An Engineering project needs every level of detail and every link.
            'levels' => [
                'structural' => '300', 'architectural' => 'N/A', 'electrical' => '200',
                'plumbing' => '200', 'mechanical-duct' => '250', 'fire-protection' => '100',
            ],
            'links' => [
                'forma' => 'https://autodeskforma.com/project',
                'ms-teams' => 'https://teams.microsoft.com/l/channel/editor',
                'ms-planner' => 'https://tasks.office.com/plan/editor',
                'ms-loop' => 'https://loop.microsoft.com/p/editor',
                'panoramic' => 'https://panoramic.example.com/tour/editor',
                'google-drive' => '/Shared drives/Projects/Editor',
            ],
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

    // Edit closed projects at every department, so a test may go on editing after Completed or Closed.
    private function headOfEveryDepartment(Employee $employee): void
    {
        DB::connection('portal')->table('access_personal')->updateOrInsert(
            ['employee_id' => $employee->getKey(), 'permission_key' => 'projects.edit.closed'],
            ['reach' => 'all'],
        );
        Cache::flush();
    }

    private const ENGINEERING_CHECKLIST = ['accRenamed' => true, 'ledgerMoved' => true, 'ledgerUpdated' => true];

    private function liveProjectId(): int
    {
        $id = (int) DB::connection('portal')->table('projects')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_lead_email' => 'nobody@ikaika.test', 'status' => 'Started']);

        return $id;
    }

    public function test_an_admin_with_auto_approve_adds_an_approved_project_with_its_people_client_and_scopes(): void
    {
        $admin = $this->employee('admin');
        // A personal default, as Nathaniel and Steven hold it: no preset gives it, so no gift can either.
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.create.direct', 'reach' => null]);
        $payload = $this->payload(['status' => 'completed']);

        $response = $this->create($this->token($admin), $payload)->assertCreated();
        $id = (int) $response->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertNotNull($row);
        // Numbered YY + client code + counter, the number leading the stored name.
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $row->project_number);
        $this->assertSame($row->project_number.' '.$payload['name'], $row->project_name);
        $this->assertSame('Approved', $row->project_creation_status);
        $this->assertSame($admin->email, $row->project_approver_email);
        $this->assertSame('Done', $row->status);
        $this->assertSame('LOD 300', $row->st_lod);
        $this->assertSame('N/A', $row->ar_lod);
        $this->assertSame('LOD 200', $row->el_lod);
        $this->assertSame('/Shared drives/Projects/Editor', $row->google_drive_folder_path);
        $this->assertSame(Employee::query()->findOrFail($payload['leadId'])->email, $row->project_lead_email);
        $this->assertSame(['member', 'support'], DB::connection('portal')->table('employees_projects')->where('project_id', $id)->orderBy('role_on_project')->pluck('role_on_project')->all());
        $this->assertSame($payload['clientId'], (int) DB::connection('portal')->table('projects_clients')->where('project_id', $id)->value('client_id'));
        $this->assertEqualsCanonicalizing($payload['scopeCodes'], DB::connection('portal')->table('projects_wbs_scopes')->where('project_id', $id)->pluck('wbs_code')->all());

        $response->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.levels.structural', '300')
            ->assertJsonPath('data.leadId', (string) $payload['leadId']);
        $this->assertTrue(DB::connection('core')->table('actions')->where('database_target', 'portal.projects')->where('record_id', (string) $id)->where('action_type', CoreActionType::ADD)->exists());
        $this->assertSame('{UserName|You} added the project '.$row->project_name, DB::connection('portal')->table('logs')->where('resource', 'projects')->where('record_id', (string) $id)->value('message'));
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
        $this->headOfEveryDepartment($admin);
        $token = $this->token($admin);
        $payload = $this->payload(['status' => 'ongoing']);
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Trail Renamed 2020', 'status' => 'completed', 'area' => 1300])->assertOk();
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Trail Renamed 2020', 'status' => 'completed', 'area' => 1300])->assertOk();
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[1]]])->assertOk();

        $name = trim($admin->first_name.' '.$admin->last_name);
        $this->assertSame([
            ['action_name' => 'Project Creation', 'created_by' => $name, 'remarks' => 'Waiting for approval'],
            ['action_name' => 'Project Edited', 'created_by' => $name, 'remarks' => 'Name: '.$payload['name'].' to Trail Renamed 2020; Status: Ongoing to Completed; Area: 1,250 to 1,300 sq ft'],
            ['action_name' => 'Scopes Changed', 'created_by' => $name, 'remarks' => '1 scope'],
        ], $this->trail($id));

        $board = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        // The board reads each stored name as Trail history words, with the kind its chip shows (2026-10-14, user).
        $this->assertSame(['Scopes changed: 1 scope', 'scopes'], [$board['trail'][0]['summary'], $board['trail'][0]['kind']]);
        $this->assertSame(['Project created: Waiting for approval', 'created'], [$board['trail'][2]['summary'], $board['trail'][2]['kind']]);
    }

    // The reported bug (2026-10-14): an edit ledgers what it changed, so the Airtable sync can send it.
    public function test_an_edit_ledgers_the_columns_and_links_it_changed(): void
    {
        $admin = $this->employee('admin');
        $this->headOfEveryDepartment($admin);
        $token = $this->token($admin);
        $payload = $this->payload(['status' => 'ongoing']);
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);
        $lastFields = fn (): array => (array) (DB::connection('core')->table('actions')->where('database_target', 'portal.projects')
            ->where('record_id', (string) $id)->where('action_type', CoreActionType::EDIT)->orderByDesc('id')
            ->value('parameters') ? json_decode((string) DB::connection('core')->table('actions')->where('database_target', 'portal.projects')
            ->where('record_id', (string) $id)->where('action_type', CoreActionType::EDIT)->orderByDesc('id')->value('parameters'), true)['parameters']['fields'] ?? [] : []);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'completed', 'area' => 1300, 'supportMemberIds' => []])->assertOk();
        $fields = $lastFields();
        $this->assertSame(['area_sqft', 'status', 'supportMemberIds'], collect(array_keys($fields))->sort()->values()->all());
        $this->assertSame([], $fields['supportMemberIds']);

        // Saved again unchanged: nothing for Airtable.
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'completed', 'area' => 1300, 'supportMemberIds' => []])->assertOk();
        $this->assertSame([], $lastFields());
    }

    // Given Add projects anywhere, a member's project still waits where no Project Manager covers its department.
    public function test_a_members_project_waits_for_a_project_manager(): void
    {
        $member = $this->employee('user', 3);
        $admin = $this->employee('admin');
        // An admin approves their own department's projects, so this one sits in the project's.
        $admin->forceFill(['department' => 'Engineering'])->save();
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
        // An admin whose Project Approver covers the project's department is told.
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $admin->getKey())->where('type', 'projects.requested')->where('link_path', '/manage/manage-projects')->exists());
        $this->assertFalse(DB::connection('portal')->table('notifications')->where('employee_id', $member->getKey())->where('type', 'projects.requested')->exists());
    }

    // Anyone may add a project (2026-10-06); only someone whose Add projects is switched off may not.
    public function test_without_add_projects_nothing_is_added(): void
    {
        $member = $this->employee('user', 3);
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'projects.create']);
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

        // Approved, it is still theirs to edit (2026-10-12) -- tested on its own below.
    }

    // The creator edits an approved project and it goes back to pending; the approvers hear it was sent again.
    public function test_the_creator_editing_an_approved_project_sends_it_back_for_approval(): void
    {
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->grant($member, ['projects.create' => 'all']);
        $payload = $this->payload();
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);
        DB::connection('portal')->table('projects')->where('id', $id)->update([
            'project_creation_status' => 'Approved', 'project_creation_approver_remarks' => 'Looks right',
        ]);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'name' => 'Approved Then Edited 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'pending')
            ->assertJsonPath('data.creationRemarks', null);
        $this->assertSame('Project Creation Resubmission', DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $id)->orderByDesc('project_action_history.id')->value('project_action_history.action_name'));

        // Manage scopes is an edit too: approved again, the creator's scopes send it back as well.
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_creation_status' => 'Approved']);
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[0]]])->assertOk();
        $this->assertSame('Pending', DB::connection('portal')->table('projects')->where('id', $id)->value('project_creation_status'));

        // Deleting stays out of the creator's reach.
        $this->withToken($token)->deleteJson(self::BASE.'/'.$id)->assertForbidden();
    }

    // A creator who may approve it themselves, and any other editor, save an approved project straight through.
    public function test_an_approved_project_stays_approved_for_auto_approve_and_other_editors(): void
    {
        // A member's approved project, edited by an admin: another editor, so it stays approved.
        $member = $this->employee('user', 3);
        $this->grant($member, ['projects.create' => 'all']);
        $payload = $this->payload();
        $memberProject = (int) $this->create($this->token($member), $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);
        DB::connection('portal')->table('projects')->where('id', $memberProject)->update(['project_creation_status' => 'Approved']);

        $admin = $this->employee('admin');
        $adminToken = $this->token($admin);
        $this->withToken($adminToken)->patchJson(self::BASE.'/'.$memberProject, [...$payload, 'name' => 'Admin Edit 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'approved');

        // The admin's own approved project, edited without Auto-approve or an approver reach over it: back to pending.
        $ownProject = (int) $this->create($adminToken, $this->payload(['name' => 'Admin Own 2020']))->assertCreated()->json('data.id');
        DB::connection('portal')->table('projects')->where('id', $ownProject)->update(['project_creation_status' => 'Approved']);
        $this->withToken($adminToken)->patchJson(self::BASE.'/'.$ownProject, [...$payload, 'name' => 'Admin Own Edited 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'pending');

        // With Auto-approve (a personal default, as Nathaniel and Steven hold it) the same edit saves straight through.
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.create.direct', 'reach' => null]);
        DB::connection('portal')->table('projects')->where('id', $ownProject)->update(['project_creation_status' => 'Approved']);
        $this->withToken($this->token($admin))->patchJson(self::BASE.'/'.$ownProject, [...$payload, 'name' => 'Admin Own Again 2020'])
            ->assertOk()
            ->assertJsonPath('data.creationStatus', 'approved');
    }

    // Completed or Closed, approved or pending: only Edit closed projects (the department heads) changes it.
    public function test_a_completed_or_closed_project_is_its_department_heads_alone(): void
    {
        $id = $this->liveProjectId();
        $admin = $this->employee('admin');
        $member = $this->employee('user', 3);
        $department = (string) $admin->department;
        DB::connection('portal')->table('projects')->where('id', $id)->update([
            'status' => 'Closed', 'project_creation_status' => 'Pending', 'requested_by' => $member->getKey(), 'department' => $department,
        ]);
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->delete();
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $member->getKey(), 'project_id' => $id, 'role_on_project' => 'member']);
        $payload = $this->payload(['status' => 'ongoing']);
        unset($payload['scopeCodes']);
        $locked = 'This project is completed or closed. Only its department head can change it.';
        // The seeded admin may be a department head already; start them without it.
        DB::connection('portal')->table('access_personal')->where('employee_id', $admin->getKey())->where('permission_key', 'projects.edit.closed')->delete();
        Cache::flush();

        // Edit projects at All, the creator, and a member on it are all refused, for the people too.
        $adminToken = $this->token($admin);
        $this->withToken($adminToken)->getJson(self::BASE.'/'.$id)->assertForbidden()->assertJsonPath('message', $locked);
        $this->withToken($adminToken)->patchJson(self::BASE.'/'.$id, $payload)->assertForbidden()->assertJsonPath('message', $locked);
        $this->withToken($adminToken)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[0]]])->assertForbidden();
        $this->withToken($this->token($member))->patchJson(self::BASE.'/'.$id, [...$payload, 'memberIds' => []])->assertForbidden();
        $board = collect($this->withToken($adminToken)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame([false, false, false], [$board['canEdit'], $board['canEditMembers'], $board['canManageScopes']]);
        // The Manage / Projects sheet hides its Edit button the same way.
        $this->withToken($adminToken)->getJson(str_replace('/projects', '/manage/projects', self::BASE).'/'.$id)->assertOk()->assertJsonPath('data.canEdit', false);

        // Done locks it the same way, approved or not.
        DB::connection('portal')->table('projects')->where('id', $id)->update(['status' => 'Done', 'project_creation_status' => 'Approved']);
        $this->withToken($adminToken)->getJson(self::BASE.'/'.$id)->assertForbidden();

        // The department head reaches their own department's finished projects, and no other department's.
        DB::connection('portal')->table('access_personal')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.edit.closed', 'reach' => 'department']);
        Cache::flush();
        $this->withToken($adminToken)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.editsPeopleOnly', false);
        DB::connection('portal')->table('projects')->where('id', $id)->update(['department' => 'Engineering']);
        if (strcasecmp($department, 'Engineering') !== 0) {
            $this->withToken($adminToken)->getJson(self::BASE.'/'.$id)->assertForbidden();
        }

        // At every department, they may reopen it.
        $this->headOfEveryDepartment($admin);
        $this->withToken($adminToken)->patchJson(self::BASE.'/'.$id, $payload)->assertOk()->assertJsonPath('data.status', 'ongoing');
    }

    // Without Edit projects, whoever View Projects reaches changes only the people, and no approval follows.
    public function test_a_viewer_changes_only_the_members_and_support_members(): void
    {
        $id = $this->liveProjectId();
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->delete();
        $before = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $payload = $this->payload(['name' => 'Not Theirs To Rename 2020', 'status' => 'closed']);
        unset($payload['scopeCodes']);

        // Not on the project: own View Projects does not reach it.
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertForbidden();
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)->assertForbidden();

        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $member->getKey(), 'project_id' => $id, 'role_on_project' => 'member']);
        Cache::flush();
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.editsPeopleOnly', true);
        $this->withToken($token)->getJson(self::BASE)->assertOk()
            ->assertJsonPath('data', fn (array $projects): bool => collect($projects)->contains(
                static fn (array $project): bool => $project['id'] === (string) $id && $project['canEdit'] === false && $project['canEditMembers'] === true,
            ));

        $supportId = (int) $payload['supportMemberIds'][0];
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'memberIds' => [$member->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.editsPeopleOnly', true)
            ->assertJsonPath('data.creationStatus', PortalViewProjectPresenter::creationStatus($before->project_creation_status));

        $after = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame($before->project_name, $after->project_name);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->project_creation_status, $after->project_creation_status);
        $roles = DB::connection('portal')->table('employees_projects')->where('project_id', $id)->get()
            ->map(static fn (object $row): string => $row->role_on_project.':'.$row->employee_id)->sort()->values()->all();
        $this->assertSame(['member:'.$member->getKey(), 'support:'.$supportId], $roles);
        $this->assertSame('Support members changed', DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $id)->orderByDesc('project_action_history.id')->value('project_action_history.remarks'));
        $this->assertTrue(DB::connection('core')->table('actions')->where('database_target', 'portal.projects')->where('record_id', (string) $id)->where('action_type', CoreActionType::EDIT)->exists());

        // Scopes are not people: still Edit projects' and Manage scopes'.
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[0]]])->assertForbidden();
    }

    // The reported ask (2026-10-14, user): a plain member may not take someone assigned to a scope off the
    // project; Remove scope assignees allows it, and Project Lead carries that power, on and locked.
    public function test_removing_a_scope_assignee_needs_the_power_project_lead_carries(): void
    {
        $portal = DB::connection('portal');
        $id = $this->liveProjectId();
        $member = $this->employee('user', 3);
        $assigned = $this->employee('user', 4);
        $unassigned = $this->employee('user', 5);
        $token = $this->token($member);
        $portal->table('employees_projects')->where('project_id', $id)->delete();
        foreach ([$member, $assigned, $unassigned] as $person) {
            $portal->table('employees_projects')->insert(['employee_id' => $person->getKey(), 'project_id' => $id, 'role_on_project' => $person->is($member) ? 'member' : 'support']);
        }
        [$scope] = $this->scopeCodes();
        $portal->table('projects_wbs_scopes')->insertOrIgnore(['project_id' => $id, 'wbs_code' => $scope]);
        $portal->table('projects_scope_assignees')->insert(['project_id' => $id, 'wbs_code' => $scope, 'employee_id' => $assigned->getKey()]);
        Cache::flush();
        $url = self::BASE.'/'.$id;

        // Someone with no scope goes freely; the assigned one is refused by name, and stays assigned.
        $this->withToken($token)->patchJson($url, ['memberIds' => [$member->getKey()], 'supportMemberIds' => [$assigned->getKey()]])->assertOk();
        $this->withToken($token)->patchJson($url, ['memberIds' => [$member->getKey()], 'supportMemberIds' => []])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have the power to remove someone assigned to this project\'s scopes ('.trim($assigned->first_name.' '.$assigned->last_name).'). Ask a Project Lead.');
        $this->assertSame(1, $portal->table('projects_scope_assignees')->where('project_id', $id)->count());

        // Project Lead carries it: a limit on it and the member's own switch cannot take it away.
        $this->grant($member, [PortalProjectPowers::LEAD => 'department']);
        $admin = Employee::query()->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->orderBy('id')->firstOrFail();
        $portal->table('access_limits')->insert(['employee_id' => $member->getKey(), 'permission_key' => PortalProjectPowers::REMOVE_ASSIGNEES, 'limited_by' => $admin->getKey(), 'as_developer' => 0]);
        $portal->table('access_toggles')->insert(['employee_id' => $member->getKey(), 'permission_key' => PortalProjectPowers::REMOVE_ASSIGNEES]);
        Cache::flush();
        $own = collect($this->withToken($token)->getJson('/api/development/portal/access')->assertOk()->json('data'))->firstWhere('key', PortalProjectPowers::REMOVE_ASSIGNEES);
        $this->assertSame([false, true, 'Project Lead'], [$own['canToggle'], $own['isActive'], $own['impliedBy']]);
        $this->withToken($token)->patchJson('/api/development/portal/access/switches', ['key' => PortalProjectPowers::REMOVE_ASSIGNEES, 'on' => false])->assertStatus(422);
        $adminToken = $this->token($admin);
        $sheet = collect($this->withToken($adminToken)->getJson('/api/development/portal/manage/access/'.$member->getKey())->assertOk()->json('data'))->firstWhere('key', PortalProjectPowers::REMOVE_ASSIGNEES);
        $this->assertSame([true, 'Project Lead'], [$sheet['holds'], $sheet['impliedBy']]);
        $this->withToken($adminToken)->patchJson('/api/development/portal/manage/access/'.$member->getKey(), ['key' => PortalProjectPowers::REMOVE_ASSIGNEES, 'action' => 'off'])
            ->assertStatus(422)->assertJsonPath('message', 'That power comes with Project Lead, so it stays on while they hold it. Switch that off first.');

        $this->withToken($token)->patchJson($url, ['memberIds' => [$member->getKey()], 'supportMemberIds' => []])->assertOk();
        $this->assertSame(0, $portal->table('projects_scope_assignees')->where('project_id', $id)->count());
    }

    // The creator changing only the people of an approved project keeps it approved.
    public function test_the_creator_changing_only_the_people_keeps_an_approved_project_approved(): void
    {
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->grant($member, ['projects.create' => 'all']);
        $payload = $this->payload();
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_creation_status' => 'Approved']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'memberIds' => [], 'supportMemberIds' => [$member->getKey()]])
            ->assertOk()
            ->assertJsonPath('data.editsPeopleOnly', false)
            ->assertJsonPath('data.creationStatus', 'approved');
    }

    // Closed is its own status (2026-10-12): stored as Airtable's 'Closed', read back as closed, never as completed.
    public function test_a_project_is_closed_as_its_own_status(): void
    {
        $admin = $this->employee('admin');
        $this->headOfEveryDepartment($admin);
        $token = $this->token($admin);
        $payload = $this->payload();
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'closed', 'closingChecklist' => self::ENGINEERING_CHECKLIST])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
        $this->assertSame('Closed', DB::connection('portal')->table('projects')->where('id', $id)->value('status'));
        $this->assertSame('closed', PortalViewProjectPresenter::status('Closed'));
        $this->assertSame('completed', PortalViewProjectPresenter::status('Done'));

        // Only Ongoing, Completed and Closed remain; On Hold and Cancelled are refused, and an old row saying one reads as ongoing.
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'on-hold'])->assertStatus(422);
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'cancelled'])->assertStatus(422);
        $this->assertSame('ongoing', PortalViewProjectPresenter::status('On Hold'));
    }

    // The reported ask: closing needs every checklist item, waits for approval again, and tells the department heads.
    public function test_closing_a_project_needs_its_checklist_and_waits_for_the_department_head(): void
    {
        $admin = $this->employee('admin');
        $this->headOfEveryDepartment($admin);
        $head = $this->employee('admin', 1);
        $this->headOfEveryDepartment($head);
        $token = $this->token($admin);
        $payload = $this->payload();
        $id = (int) $this->create($token, $payload)->assertCreated()->json('data.id');
        unset($payload['scopeCodes']);
        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_creation_status' => 'Approved']);
        $close = fn (array $checklist) => $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'closed', 'closingChecklist' => $checklist]);
        $refused = 'Tick every item on the completion checklist before closing the project.';

        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'closed'])->assertStatus(422)->assertJsonPath('message', $refused);
        $close([...self::ENGINEERING_CHECKLIST, 'ledgerUpdated' => false])->assertStatus(422)->assertJsonPath('message', $refused);
        $this->assertSame('Started', DB::connection('portal')->table('projects')->where('id', $id)->value('status'));

        $close(self::ENGINEERING_CHECKLIST)->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.creationStatus', 'pending');
        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame([1, 1, 1, 'Pending', null], [
            (int) $row->is_renamed_with_acc_number, (int) $row->is_ledger_moved_to_for_submission, (int) $row->is_ledger_details_updated,
            $row->project_creation_status, $row->project_creation_approver,
        ]);
        $this->assertContains('Project Closing Submission', array_column($this->trail($id), 'action_name'));
        $this->assertTrue(DB::connection('portal')->table('notifications')
            ->where('employee_id', $head->getKey())->where('title', 'Project closed')->where('link_path', '/manage/manage-projects')->exists());

        // Saving it again while Closed asks for nothing more.
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, [...$payload, 'status' => 'closed'])->assertOk();

        // The head's decision on a Closed project is its closing, and the trail says so.
        DB::connection('portal')->table('access_personal')->updateOrInsert(['employee_id' => $head->getKey(), 'permission_key' => 'projects.manager'], ['reach' => 'all']);
        Cache::flush();
        $this->withToken($this->token($head))->patchJson('/api/development/portal/manage/projects/'.$id, ['decision' => 'approve'])->assertOk();
        $this->assertSame('Project Closing Approved', DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $id)->orderByDesc('project_action_history.id')->value('project_action_history.action_name'));

        // Another department confirms once.
        $mis = $this->payload(['name' => 'Closing Tool '.Str::random(6), 'department' => 'MIS', 'leadId' => $admin->id, 'scopeCodes' => []]);
        $misId = (int) $this->create($token, $mis)->assertCreated()->json('data.id');
        $this->withToken($token)->patchJson(self::BASE.'/'.$misId, [...$mis, 'status' => 'closed', 'closingChecklist' => self::ENGINEERING_CHECKLIST])->assertStatus(422);
        $this->withToken($token)->patchJson(self::BASE.'/'.$misId, [...$mis, 'status' => 'closed', 'closingChecklist' => ['workFinished' => true]])
            ->assertOk()->assertJsonPath('data.status', 'closed');
    }

    // A project starts with its type's whole activity list and may only take codes out of it, never add one.
    public function test_a_project_takes_activities_out_of_its_type_and_reports_offer_the_rest(): void
    {
        $codes = array_slice(array_column(PortalActivityScopes::all(), 'code'), 0, 4);
        [$kept, $removed, $alsoKept, $outside] = $codes;
        $portal = DB::connection('portal');
        foreach (['Scan-to-BIM' => [$kept, $removed, $alsoKept], 'CAD-to-BIM' => [$kept, $alsoKept]] as $type => $allowed) {
            $portal->table('job_type_allowed_activities')->where('job_type', $type)->delete();
            $portal->table('job_type_allowed_activities')->insert(['job_type' => $type, 'activity_code_id_initials' => implode(',', $allowed)]);
        }
        $admin = $this->employee('admin');
        $portal->table('access_personal')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.create.direct', 'reach' => null]);
        $token = $this->token($admin);
        $payload = $this->payload(['removedActivityCodes' => [$removed, $outside]]);

        // A code the type does not allow cannot be taken out, as it was never in.
        $id = (int) $this->create($token, $payload)->assertCreated()
            ->assertJsonPath('data.removedActivityCodes', [$removed])
            ->json('data.id');
        $stored = fn (): array => $portal->table('projects_removed_activities')->where('project_id', $id)->pluck('activity_code')->all();
        $this->assertSame([$removed], $stored());

        // Daily and Late Reports offer the type's list less what this project took out.
        $member = Employee::query()->findOrFail($payload['memberIds'][0]);
        Cache::flush();
        $picker = app(PortalReportProjects::class)->list($member);
        $project = collect($picker['data'])->firstWhere('id', (string) $id);
        $this->assertNotNull($project);
        $group = collect($picker['activityGroups'])->firstWhere('key', $project['activityGroup']);
        $offered = implode('|', $group['activities']);
        $this->assertStringContainsString($kept, $offered);
        $this->assertStringContainsString($alsoKept, $offered);
        $this->assertStringNotContainsString($removed, $offered);

        // An edit that does not send the list leaves it; one that sends it replaces it.
        $edit = fn (array $overrides) => $this->withToken($token)->patchJson(self::BASE.'/'.$id, $this->payload(['name' => $payload['name'], ...$overrides]));
        $edit(['leadId' => $payload['leadId'], 'memberIds' => $payload['memberIds']])->assertOk();
        $this->assertSame([$removed], $stored());
        $edit(['leadId' => $payload['leadId'], 'removedActivityCodes' => []])->assertOk()->assertJsonPath('data.removedActivityCodes', []);
        $this->assertSame([], $stored());
        $this->assertContains('Allowed activities changed', array_column($this->trail($id), 'remarks'));

        // A new type keeps only the removals it still allows.
        $edit(['leadId' => $payload['leadId'], 'removedActivityCodes' => [$removed, $kept]])->assertOk();
        $edit(['leadId' => $payload['leadId'], 'type' => 'CAD-to-BIM'])->assertOk();
        $this->assertSame([$kept], $stored());
    }

    // The reported ask: Management is a type in several departments, each with its own allowed activities.
    public function test_a_departments_own_list_for_a_type_wins_over_the_shared_one(): void
    {
        $portal = DB::connection('portal');
        [$engineeringCode, $misCode, $sharedCode] = array_slice(array_column(PortalActivityScopes::all(), 'code'), 0, 3);
        $portal->table('job_types')->insertOrIgnore([
            ['department' => 'Engineering', 'name' => 'Management'],
            ['department' => 'MIS', 'name' => 'Management'],
        ]);
        $portal->table('job_type_allowed_activities')->where('job_type', 'Management')->delete();
        $portal->table('job_type_allowed_activities')->insert([
            ['job_type' => 'Management', 'department' => null, 'activity_code_id_initials' => $sharedCode],
            ['job_type' => 'Management', 'department' => 'Engineering', 'activity_code_id_initials' => $engineeringCode],
        ]);
        $activities = app(PortalProjectActivities::class);

        $this->assertSame([$engineeringCode], $activities->allowedFor('engineering', 'Management'));
        $this->assertSame([$sharedCode], $activities->allowedFor('MIS', 'Management'));
        $this->assertSame([], $activities->allowedFor('MIS', null));

        $admin = $this->employee('admin');
        $this->grant($admin, ['projects.edit' => 'all']);
        $token = $this->token($admin);
        $options = $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();
        $this->assertSame([$engineeringCode], $options->json('data.allowedActivitiesByDepartment.Engineering.Management'));

        // A name more than one department uses keeps one list per department.
        $this->withToken($token)->putJson(self::BASE.'/allowed-activities', ['jobType' => 'Management', 'department' => 'MIS', 'activityCodes' => [$misCode]])
            ->assertOk()->assertJsonPath('data.department', 'MIS');
        $this->assertSame([$misCode], $activities->allowedFor('MIS', 'Management'));
        $this->assertSame([$engineeringCode], $activities->allowedFor('Engineering', 'Management'));
        $this->withToken($token)->putJson(self::BASE.'/allowed-activities', ['jobType' => 'Management', 'department' => 'Nowhere', 'activityCodes' => []])->assertStatus(422);
    }

    public function test_adding_refuses_what_is_not_on_the_lists(): void
    {
        $token = $this->token($this->employee('admin'));
        $inactive = (int) DB::connection('portal')->table('employees')->whereRaw("LOWER(COALESCE(status, '')) != 'active'")->value('id');

        $this->create($token, $this->payload(['scopeCodes' => ['999-99']]))->assertStatus(422)->assertJsonPath('message', 'Choose scopes from the list.');
        $this->create($token, $this->payload(['links' => [...$this->payload()['links'], 'forma' => 'javascript:alert(1)']]))->assertStatus(422)->assertJsonPath('message', 'Each link must start with http:// or https://, or be N/A.');
        $this->create($token, $this->payload(['links' => [...$this->payload()['links'], 'forma' => ' n/a ']]))->assertCreated()->assertJsonPath('data.links.forma', 'N/A');
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
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->where('employee_id', $member->getKey())->delete();
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
        $portal->table('projects')->whereIn('id', [$on, $led])->update(['project_creation_status' => 'Approved', 'requested_by' => null, 'status' => 'Started']);
        $portal->table('projects')->where('id', $on)->update(['project_lead_email' => 'nobody@ikaika.test']);
        $portal->table('projects')->where('id', $led)->update(['project_lead_email' => $member->email]);
        $portal->table('employees_projects')->where('employee_id', $member->getKey())->whereIn('project_id', [$on, $led])->delete();
        $portal->table('employees_projects')->insert(['employee_id' => $member->getKey(), 'project_id' => $on, 'role_on_project' => 'member']);

        // On it but not leading: "own" reaches it, "lead" does not.
        $this->grant($member, ['projects.edit' => 'own']);
        $this->withToken($token)->getJson(self::BASE.'/'.$on)->assertOk()->assertJsonPath('data.editsPeopleOnly', false);
        $this->withToken($token)->getJson(self::BASE.'/'.$led)->assertForbidden();

        // Leading it but not on it: "lead" reaches it; "own" does not, so the one they are on opens for its people only.
        $portal->table('access_grants')->where('employee_id', $member->getKey())->where('permission_key', 'projects.edit')->update(['reach' => 'lead']);
        $this->withToken($token)->getJson(self::BASE.'/'.$on)->assertOk()->assertJsonPath('data.editsPeopleOnly', true);
        $this->withToken($token)->getJson(self::BASE.'/'.$led)->assertOk()->assertJsonPath('data.editsPeopleOnly', false);
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
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->where('employee_id', $member->getKey())->delete();

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
        DB::connection('portal')->table('employees_projects')->where('project_id', $id)->where('employee_id', $member->getKey())->delete();
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

    // Manage Scopes shows each assignee's head, so the people list carries their photo when they have one.
    public function test_the_form_options_carry_each_persons_photo(): void
    {
        $person = $this->employee('user', 3);
        DB::connection('portal')->table('attachments')->insert([
            'table_name' => 'employees', 'record_id' => $person->getKey(), 'field_name' => 'photo',
            'file_url' => 'https://res.cloudinary.com/demo/image/upload/head.jpg', 'file_name' => 'head.jpg',
        ]);

        $employees = collect($this->withToken($this->token($this->employee('admin')))->getJson(self::BASE.'/options')
            ->assertOk()->json('data.employees'))->keyBy('id');

        $id = (int) $person->getKey();
        $this->assertNotNull($employees[(string) $id]['photoUrl']);
        $this->assertSame(PortalEmployeePresenter::photoUrls([$id])[$id], $employees[(string) $id]['photoUrl']);
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

        // Every member may add, so the form opens for them; with Add projects switched off and nothing
        // else, it is closed, and given it back it opens again.
        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();

        // View Projects opens them too, for the members-only edit, so both are switched off to close them.
        DB::connection('portal')->table('access_toggles')->insert([
            ['employee_id' => $member->getKey(), 'permission_key' => 'projects.create'],
            ['employee_id' => $member->getKey(), 'permission_key' => 'projects.view'],
        ]);
        Cache::flush();
        $this->withToken($token)->getJson(self::BASE.'/options')->assertForbidden();

        DB::connection('portal')->table('access_toggles')->where('employee_id', $member->getKey())->delete();
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

    // Being an admin, or a Project Approver over every project, no longer passes your own project (2026-10-07).
    public function test_an_admin_without_auto_approve_waits_like_anyone(): void
    {
        $admin = $this->employee('admin');

        $id = (int) $this->create($this->token($admin), $this->payload())->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame('Pending', $row->project_creation_status);
        $this->assertNull($row->project_creation_approver);
    }

    // Auto-approve is an admin's at most: a member given it still waits.
    public function test_auto_approve_never_reaches_a_member(): void
    {
        $member = $this->employee('user', 3);
        $member->forceFill(['department' => 'MIS'])->save();
        $this->grant($member, ['projects.create' => 'all', 'projects.create.direct' => null]);

        $id = (int) $this->create($this->token($member), $this->payload())->assertCreated()->json('data.id');

        $this->assertSame('Pending', DB::connection('portal')->table('projects')->where('id', $id)->value('project_creation_status'));
    }

    // The reported ask: one name may be used once per department, so two departments can each have it.
    public function test_a_name_is_taken_only_within_its_own_department(): void
    {
        $admin = $this->employee('admin');
        $token = $this->token($admin);
        $name = 'Shared Name '.Str::random(6);
        $mis = fn (): array => $this->payload([
            'name' => strtolower($name), 'department' => 'MIS', 'leadId' => $admin->id, 'scopeCodes' => [],
        ]);

        $this->create($token, $this->payload(['name' => $name]))->assertCreated();
        $this->create($token, $mis())->assertCreated();

        $this->create($token, $this->payload(['name' => '  '.$name.' ']))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Engineering already has a project named "'.$name.'"'));
        $this->create($token, $mis())
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'MIS already has a project named'));
    }

    // The reported ask: a member adds for their own department only, unless someone widens it for them.
    public function test_a_member_adds_projects_for_their_own_department_only(): void
    {
        $member = $this->employee('user', 3);
        $member->forceFill(['department' => 'MIS'])->save();
        foreach (['access_grants', 'access_personal', 'access_toggles', 'access_limits', 'access_self_reach'] as $table) {
            DB::connection('portal')->table($table)->where('employee_id', $member->getKey())->where('permission_key', 'like', 'projects.create%')->delete();
        }
        Cache::flush();
        $token = $this->token($member);

        $this->assertSame(['MIS'], $this->withToken($token)->getJson(self::BASE.'/options')->assertOk()->json('data.addDepartments'));
        $this->create($token, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Your Add projects power does not reach that department.');
    }

    // Level of detail, area, Forma and panoramic are Engineering's: another department's project has them cleared.
    public function test_a_non_engineering_project_drops_the_engineering_fields(): void
    {
        $admin = $this->employee('admin');
        $payload = $this->payload([
            'department' => 'MIS',
            'leadId' => $admin->id,
            'scopeCodes' => [],
            'links' => [
                'forma' => 'https://autodeskforma.com/p', 'panoramic' => 'https://pano.example.com', 'ms-teams' => 'https://teams.microsoft.com/l/1',
                'ms-planner' => 'https://tasks.office.com/plan/1', 'ms-loop' => 'https://loop.microsoft.com/p/1', 'google-drive' => '/Shared drives/Projects/MIS',
            ],
        ]);

        $id = (int) $this->create($this->token($admin), $payload)->assertCreated()->json('data.id');

        $row = DB::connection('portal')->table('projects')->where('id', $id)->first();
        $this->assertSame([null, null, null, null], [$row->st_lod, $row->area_sqft, $row->forma_link, $row->panoramic_link]);
        $this->assertSame('https://teams.microsoft.com/l/1', $row->msteams_project_link);
    }

    // Each department picks from its own scope tree; another department's report codes never show as scopes.
    public function test_a_project_picks_scopes_from_its_own_departments_tree(): void
    {
        $portal = DB::connection('portal');
        $portal->table('project_scope_t1_processes')->insert(['wbs_code' => '910', 'name' => 'MIS Planning', 'department' => 'MIS']);
        $portal->table('project_scope_t2_procedures')->insert(['wbs_code' => '910-01', 'name' => 'Sprint planning', 'department' => 'MIS']);
        $id = $this->liveProjectId();
        $token = $this->token($this->employee('admin'));
        $portal->table('projects')->where('id', $id)->update(['department' => 'MIS']);
        Cache::flush();

        // Its report codes are activities, not scopes, so nothing shows until it picks some.
        $board = $this->withToken($token)->getJson(self::BASE)->assertOk();
        $this->assertSame([], collect($board->json('data'))->firstWhere('id', (string) $id)['scopeCodes']);
        $this->assertContains('910', array_column($board->json('scopeTree'), 'code'));
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.scopeCodes', []);
        $this->assertSame('MIS', collect($this->withToken($token)->getJson(self::BASE.'/options')->json('data.scopeTree'))->firstWhere('code', '910')['department']);

        // Engineering's codes are not MIS's.
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$this->scopeCodes()[0]]])
            ->assertStatus(422)->assertJsonPath('message', 'Choose scopes from the list.');
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => ['910', '910-01']])->assertOk();
        Cache::flush();
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.scopeCodes', ['910', '910-01']);
    }

    // The reported ask: a scope is assigned to the project's own people; the record shows who and how far.
    public function test_scopes_are_assigned_to_the_projects_people_and_show_their_progress(): void
    {
        $portal = DB::connection('portal');
        $id = $this->liveProjectId();
        $admin = $this->employee('admin');
        $token = $this->token($admin);
        $member = $this->employee('user', 3);
        $stranger = $this->employee('user', 4);
        $portal->table('employees_projects')->where('project_id', $id)->delete();
        $portal->table('employees_projects')->insert(['employee_id' => $member->getKey(), 'project_id' => $id, 'role_on_project' => 'member']);
        $portal->table('projects')->where('id', $id)->update(['department' => 'Engineering']);
        Cache::flush();
        [$scope] = $this->scopeCodes();

        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$scope], 'scopeAssignees' => [$scope => [$stranger->getKey()]]])
            ->assertStatus(422)->assertJsonPath('message', 'Assign a scope only to the project\'s members and support members.');
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => [$scope], 'scopeAssignees' => [$scope => [$member->getKey()], '999' => [$member->getKey()]]])->assertOk();
        $this->assertSame([$scope], $portal->table('projects_scope_assignees')->where('project_id', $id)->pluck('wbs_code')->all());

        // Progress counts only from an approved report, so it rides on one that never waited.
        $approvedLine = (int) $portal->table('employees_user_reports')->join('user_reports', 'user_reports.id', '=', 'employees_user_reports.user_report_id')
            ->where('employees_user_reports.is_flag', 0)->where(fn ($daily) => $daily->whereNull('user_reports.late_submission')->orWhere('user_reports.late_submission', 0))
            ->value('user_reports.id');
        $portal->table('projects_scope_progress')->insert(['project_id' => $id, 'wbs_code' => $scope, 'employee_id' => $member->getKey(), 'user_report_id' => $approvedLine, 'percent' => 60]);
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()
            ->assertJsonPath('data.scopeAssignees.'.$scope, [(string) $member->getKey()])
            ->assertJsonPath('data.scopeProgress.'.$scope, 60);
        Cache::flush();
        $board = collect($this->withToken($token)->getJson(self::BASE)->assertOk()->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame(60, $board['scopeProgress'][$scope]);
        $this->assertSame([trim($member->first_name.' '.$member->last_name)], $board['scopeAssignees'][$scope]);

        // A scope taken off takes its assignees along.
        $this->withToken($token)->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => []])->assertOk();
        $this->assertSame(0, $portal->table('projects_scope_assignees')->where('project_id', $id)->count());
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
