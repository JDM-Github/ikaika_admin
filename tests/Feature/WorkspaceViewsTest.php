<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

class WorkspaceViewsTest extends TestCase
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

    public function test_a_view_is_saved_listed_and_opened_as_the_query_it_came_from(): void
    {
        $headers = $this->authHeaders();
        $query = 'cols=id,project_name,status&sort=project_name:asc&filter[status]=eq:Closed';

        $saved = $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Closed work', 'query' => $query],
            $headers,
        );

        $saved->assertCreated()
            ->assertJsonPath('name', 'Closed work')
            ->assertJsonPath('shared', false)
            ->assertJsonPath('mine', true);

        $listed = $this->getJson('/api/development/portal/_views/projects', $headers);
        $listed->assertOk()->assertJsonPath('views.0.name', 'Closed work');

        // The view opens the grid it was saved from, filter and all.
        $grid = $this->getJson(
            '/api/development/portal/_grid/projects?'.$listed->json('views.0.query'),
            $headers,
        );
        $grid->assertOk()
            ->assertJsonPath('meta.filters.status', 'eq:Closed')
            ->assertJsonPath('meta.sort', 'project_name:asc');

        $this->assertLessThan($grid->json('meta.unfiltered_total'), $grid->json('meta.total'));
    }

    public function test_saving_the_same_name_twice_replaces_the_view_instead_of_doubling_it(): void
    {
        $headers = $this->authHeaders();

        $first = $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Mine', 'query' => 'filter[status]=eq:Closed'],
            $headers,
        )->assertCreated();

        $second = $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Mine', 'query' => 'filter[status]=eq:Ongoing'],
            $headers,
        )->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));

        $views = $this->getJson('/api/development/portal/_views/projects', $headers)->json('views');
        $this->assertCount(1, array_filter($views, fn (array $view): bool => $view['name'] === 'Mine'));
        $this->assertSame('filter%5Bstatus%5D=eq%3AOngoing', $second->json('query'));
    }

    public function test_a_view_keeps_only_the_parameters_that_describe_the_question(): void
    {
        $saved = $this->postJson(
            '/api/development/portal/_views/projects',
            [
                'name' => 'Tidy',
                // page is a scroll position, base and table are already in the route,
                // and row is whichever record happened to be open.
                'query' => 'cols=id&page=7&base=portal&table=projects&row=12&filter[status]=eq:Closed',
            ],
            $this->authHeaders(),
        )->assertCreated();

        parse_str($saved->json('query'), $parsed);

        $this->assertSame(['cols', 'filter'], array_keys($parsed));
        $this->assertSame(['status' => 'eq:Closed'], $parsed['filter']);
    }

    public function test_a_private_view_stays_private_and_a_shared_one_reaches_everyone(): void
    {
        $admin = $this->administrator();
        $other = $this->otherAdministrator($admin->id);

        $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Just mine', 'query' => 'cols=id'],
            $this->headersFor($admin),
        )->assertCreated();

        $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Everyone', 'query' => 'cols=id', 'shared' => true],
            $this->headersFor($admin),
        )->assertCreated();

        $names = array_column(
            $this->getJson('/api/development/portal/_views/projects', $this->headersFor($other))->json('views'),
            'name',
        );

        $this->assertContains('Everyone', $names);
        $this->assertNotContains('Just mine', $names);
    }

    public function test_a_project_admin_keeps_private_views_but_cannot_share_one(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', $member->id)->update(['role' => 'ProjectAdmin']);
        $headers = $this->headersFor($member->fresh());

        $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'My shortlist', 'query' => 'cols=id'],
            $headers,
        )->assertCreated();

        $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'For everyone', 'query' => 'cols=id', 'shared' => true],
            $headers,
        )
            ->assertForbidden()
            ->assertJsonPath('message', 'Only administrators can share a view with everyone.');
    }

    public function test_a_view_belonging_to_someone_else_cannot_be_deleted(): void
    {
        $admin = $this->administrator();
        $other = $this->otherAdministrator($admin->id);

        $view = $this->postJson(
            '/api/development/portal/_views/projects',
            ['name' => 'Mine alone', 'query' => 'cols=id'],
            $this->headersFor($admin),
        )->assertCreated();

        $this->deleteJson('/api/development/portal/_views/'.$view->json('id'), [], $this->headersFor($other))
            ->assertForbidden();

        $this->deleteJson('/api/development/portal/_views/'.$view->json('id'), [], $this->headersFor($admin))
            ->assertOk();

        $this->assertSame(
            0,
            DB::connection('core')->table('workspace_views')->where('id', $view->json('id'))->count(),
        );
    }

    public function test_views_are_refused_for_a_table_that_is_not_browsable(): void
    {
        // employees_projects is a link between two tables, not a table of its own.
        $this->getJson('/api/development/portal/_views/employees_projects', $this->authHeaders())
            ->assertNotFound();

        $this->getJson('/api/development/portal/_views/projects', $this->headersFor($this->member()))
            ->assertForbidden();
    }

    private function otherAdministrator(int $notThisOne): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('id', '!=', $notThisOne)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        DB::connection('portal')->table('employees')->where('id', $employee->id)->update(['role' => 'Admin']);

        return $employee->fresh();
    }
}
