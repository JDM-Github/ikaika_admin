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
 * projects (projects.view.edit), which a project's lead never needs for their own. Every write lands in
 * SQL with a ledger action and a log row, and View Projects reads the saved scopes first.
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

    public function test_a_member_cannot_add_a_project(): void
    {
        $before = DB::connection('portal')->table('projects')->count();

        $this->create($this->token($this->employee('user', 3)), $this->payload())
            ->assertForbidden()
            ->assertJsonPath('message', 'Adding a project needs the Add projects power.');
        $this->assertSame($before, DB::connection('portal')->table('projects')->count());
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
        $payload = $this->payload();
        unset($payload['scopeCodes']);

        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertForbidden();
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'Editing a project needs the Edit projects power, unless you lead it.');

        DB::connection('portal')->table('projects')->where('id', $id)->update(['project_lead_email' => strtoupper((string) $member->email)]);
        $this->withToken($token)->getJson(self::BASE.'/'.$id)->assertOk()->assertJsonPath('data.id', (string) $id);
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, $payload)->assertOk();
    }

    // Switched off, Edit projects is gone even for an admin who leads nothing.
    public function test_an_admin_without_edit_projects_is_refused(): void
    {
        $id = $this->liveProjectId();
        $admin = $this->employee('admin');
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'projects.view.edit']);

        $this->withToken($this->token($admin))->putJson(self::BASE.'/'.$id.'/scopes', ['scopeCodes' => $this->scopeCodes()])->assertForbidden();
        $this->assertFalse(DB::connection('portal')->table('projects_wbs_scopes')->where('project_id', $id)->exists());
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
        $this->withToken($this->token($this->employee('admin')))->getJson(self::BASE.'/options')
            ->assertOk()
            ->assertJsonPath('data.departments', fn (array $departments): bool => in_array('Engineering', $departments, true))
            ->assertJsonStructure(['data' => ['employees' => [['id', 'name', 'canLead']], 'clients' => [['id', 'name']], 'types']]);

        $member = $this->employee('user', 3);
        $token = $this->token($member);
        $this->withToken($token)->getJson(self::BASE.'/options')->assertForbidden();

        DB::connection('portal')->table('projects')->where('id', $this->liveProjectId())->update(['project_lead_email' => $member->email]);
        $this->withToken($token)->getJson(self::BASE.'/options')->assertOk();
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
