<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Support\Core\CoreActionType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The one write path out of the workspace. These run against the live schema inside a
 * transaction, so an assertion failure never leaves a corrected value behind.
 */
class WorkspaceWriteTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_an_administrator_corrects_a_cell_and_the_ledger_records_what_changed(): void
    {
        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);

        $before = (string) $project->project_name;
        $after = mb_substr($before, 0, 60).' (checked)';
        $admin = $this->administrator();

        $response = $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            ['changes' => ['project_name' => $after]],
            $this->headersFor($admin),
        );

        $response->assertOk()
            ->assertJsonPath('table', 'projects')
            ->assertJsonPath('changed.project_name.from', $before)
            ->assertJsonPath('changed.project_name.to', $after)
            ->assertJsonPath('data.project_name.v', $after);

        $this->assertSame($after, DB::connection('portal')->table('projects')->where('id', $project->id)->value('project_name'));

        $action = Action::query()->where('id', $response->json('action_id'))->first();
        $this->assertNotNull($action);
        $this->assertSame(CoreActionType::EDIT, $action->action_type);
        $this->assertSame('portal', $action->product);
        $this->assertSame('projects', $action->resource);
        $this->assertSame((string) $project->id, (string) $action->record_id);
        $this->assertSame((string) $admin->id_no, (string) $action->actor_id_no);

        $this->assertSame($before, $action->parameters['parameters']['changes']['project_name']['from']);
        $this->assertSame($after, $action->parameters['parameters']['changes']['project_name']['to']);
    }

    public function test_a_value_that_did_not_move_writes_nothing_and_logs_nothing(): void
    {
        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);

        $before = Action::query()->count();

        $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            ['changes' => ['project_name' => $project->project_name]],
            $this->authHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('changed', [])
            ->assertJsonPath('action_id', 0);

        $this->assertSame($before, Action::query()->count());
    }

    public function test_a_row_someone_else_moved_is_refused_rather_than_overwritten(): void
    {
        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);

        $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            [
                'changes' => ['project_name' => 'Mine'],
                'expect' => ['project_name' => 'what I loaded, which is not what is there'],
            ],
            $this->authHeaders(),
        )->assertStatus(409);

        $this->assertSame(
            $project->project_name,
            DB::connection('portal')->table('projects')->where('id', $project->id)->value('project_name'),
        );
    }

    public function test_keys_links_and_external_ids_are_not_editable(): void
    {
        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);
        $headers = $this->authHeaders();

        foreach (['id' => 9, 'airtable_record_id' => 'recFAKE', 'link__clients' => '4'] as $column => $value) {
            $this->patchJson(
                '/api/development/portal/_grid/projects/'.$project->id,
                ['changes' => [$column => $value]],
                $headers,
            )->assertStatus(422);
        }

        $fresh = DB::connection('portal')->table('projects')->where('id', $project->id)->first();
        $this->assertSame($project->airtable_record_id, $fresh->airtable_record_id);
    }

    public function test_a_value_the_column_cannot_hold_is_refused_before_it_reaches_mysql(): void
    {
        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);
        $headers = $this->authHeaders();

        // project_number is varchar(100); a silent truncation would show the editor a
        // value the database never stored.
        $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            ['changes' => ['project_number' => str_repeat('x', 120)]],
            $headers,
        )->assertStatus(422);

        $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            ['changes' => ['due_date' => 'the day after tomorrow, roughly']],
            $headers,
        )->assertStatus(422);

        $this->assertSame(
            $project->project_number,
            DB::connection('portal')->table('projects')->where('id', $project->id)->value('project_number'),
        );
    }

    public function test_a_project_admin_may_browse_but_never_write(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', $member->id)->update(['role' => 'ProjectAdmin']);
        $headers = $this->headersFor($member->fresh());

        $project = DB::connection('portal')->table('projects')->orderBy('id')->first();
        $this->assertNotNull($project);

        $this->getJson('/api/development/portal/_grid/projects?per_page=1', $headers)->assertOk();

        $this->patchJson(
            '/api/development/portal/_grid/projects/'.$project->id,
            ['changes' => ['project_name' => 'Nope']],
            $headers,
        )
            ->assertForbidden()
            ->assertJsonPath('message', 'Editing the databases directly is limited to administrators.');

        // The schema says so up front rather than only refusing on submit.
        $this->getJson('/api/development/portal/_schema/projects', $headers)
            ->assertOk()
            ->assertJsonPath('editable', false);
    }

    public function test_the_core_database_is_never_a_write_target(): void
    {
        $this->getJson('/api/development/core/_schema/settings', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('editable', false);
    }
}
