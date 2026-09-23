<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

class WorkspaceGridTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_a_page_of_rows_comes_back_as_typed_cells_with_an_honest_count(): void
    {
        $response = $this->getJson('/api/development/portal/_grid/projects?per_page=5', $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('table', 'projects')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.filtered', false);

        $this->assertSame($response->json('meta.total'), $response->json('meta.unfiltered_total'));
        $this->assertCount(5, $response->json('data'));

        // Every cell is an envelope, so null, empty string and zero stay distinct.
        $first = $response->json('data.0');
        $this->assertArrayHasKey('v', $first['id']);
        $this->assertArrayHasKey('v', $first['project_name']);
        $this->assertIsString($first['project_name']['v']);
    }

    public function test_a_filter_narrows_the_total_but_never_the_unfiltered_total(): void
    {
        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[status]=eq:Closed',
            $this->authHeaders(),
        );

        $response->assertOk()
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('meta.filters.status', 'eq:Closed');

        $total = $response->json('meta.total');
        $unfiltered = $response->json('meta.unfiltered_total');
        $this->assertGreaterThan(0, $total);
        $this->assertLessThan($unfiltered, $total);

        foreach ($response->json('data') as $row) {
            $this->assertSame('Closed', $row['status']['v']);
        }
    }

    public function test_a_filtered_or_sorted_column_is_added_to_the_key_preset(): void
    {
        // The estimator's projects table has nine LOD selects, so a column can easily
        // sit outside the preset while it is the very column narrowing the page.
        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[type_of_job]=filled&sort=due_date:desc',
            $this->authHeaders(),
        );

        $response->assertOk();
        $columns = $response->json('columns');
        $this->assertContains('type_of_job', $columns);
        $this->assertContains('due_date', $columns);

        // An unknown column is still not smuggled into the select list.
        $this->getJson('/api/development/portal/_grid/projects?filter[drop_table]=eq:1', $this->authHeaders())
            ->assertOk()
            ->assertJsonMissing(['columns' => ['drop_table']]);
    }

    public function test_empty_and_filled_partition_a_column_between_them(): void
    {
        // Both arrive with no colon to split on, so a naive split read them as the
        // literal value "empty" and quietly matched nothing.
        $blank = DB::connection('portal')->table('projects')
            ->where(function ($query): void {
                $query->whereNull('type_of_job')->orWhere('type_of_job', '=', '');
            })
            ->count();
        $this->assertGreaterThan(0, $blank);

        $this->getJson('/api/development/portal/_grid/projects?filter[type_of_job]=empty', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', $blank)
            ->assertJsonPath('meta.filtered', true);

        $filled = $this->getJson('/api/development/portal/_grid/projects?filter[type_of_job]=filled', $this->authHeaders());
        $filled->assertOk();
        $this->assertSame($filled->json('meta.unfiltered_total') - $blank, $filled->json('meta.total'));
    }

    public function test_search_and_sort_are_allow_listed_against_real_columns(): void
    {
        $sorted = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=10&cols=all&sort=project_name:desc',
            $this->authHeaders(),
        );
        $sorted->assertOk()->assertJsonPath('meta.sort', 'project_name:desc');

        $names = array_map(fn (array $row): string => (string) $row['project_name']['v'], $sorted->json('data'));
        $descending = $names;
        rsort($descending, SORT_STRING);
        $this->assertSame($descending, $names);

        // An unknown sort column falls back to the key rather than reaching SQL.
        $this->getJson('/api/development/portal/_grid/projects?sort=drop_table:desc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.sort', 'id:asc');

        $searched = $this->getJson('/api/development/portal/_grid/clients?q=a', $this->authHeaders());
        $searched->assertOk()->assertJsonPath('meta.q', 'a')->assertJsonPath('meta.filtered', true);
    }

    public function test_linked_records_render_as_chips_resolved_in_one_query_per_column(): void
    {
        $queries = [];
        DB::connection('portal')->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=25&cols=id,project_name,link__clients,link__employees__member',
            $this->authHeaders(),
        );
        $response->assertOk();

        $withChips = null;
        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('chips', $row['link__clients']);
            $this->assertArrayHasKey('more', $row['link__clients']);
            $this->assertSame('clients', $row['link__clients']['to']);
            if ($withChips === null && $row['link__clients']['chips'] !== []) {
                $withChips = $row['link__clients']['chips'][0];
            }
        }

        $this->assertNotNull($withChips, 'No project resolved a client chip.');
        $this->assertArrayHasKey('id', $withChips);
        $this->assertArrayHasKey('label', $withChips);
        $this->assertNotSame('', trim((string) $withChips['label']));

        // One batched join per link column, never one per row.
        $this->assertSame(1, $this->countContaining($queries, 'from `projects_clients` as `j`'));
        $this->assertSame(1, $this->countContaining($queries, 'from `employees_projects` as `j`'));
    }

    public function test_a_cell_holds_at_most_the_configured_chip_count_and_reports_the_rest(): void
    {
        config(['workspace.chips_per_cell' => 2]);

        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=50&cols=id,link__employees__member',
            $this->authHeaders(),
        );
        $response->assertOk();

        $sawOverflow = false;
        foreach ($response->json('data') as $row) {
            $this->assertLessThanOrEqual(2, count($row['link__employees__member']['chips']));
            if ($row['link__employees__member']['more'] > 0) {
                $sawOverflow = true;
            }
        }

        $this->assertTrue($sawOverflow, 'No project has more than two members, so overflow was never exercised.');
    }

    public function test_a_record_expands_with_every_field_and_every_chip(): void
    {
        $id = DB::connection('portal')->table('projects')->orderBy('id')->value('id');
        $this->assertNotNull($id);

        $response = $this->getJson('/api/development/portal/_grid/projects/'.$id, $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('table', 'projects')
            ->assertJsonPath('id', (int) $id);

        $this->assertArrayHasKey('project_name', $response->json('data'));
        $this->assertArrayHasKey('link__clients', $response->json('data'));

        $this->getJson('/api/development/portal/_grid/projects/99999999', $this->authHeaders())
            ->assertNotFound();
    }

    public function test_a_member_is_turned_away_from_the_workspace_with_a_reason(): void
    {
        $headers = $this->headersFor($this->member());

        $this->getJson('/api/development/portal/_schema', $headers)
            ->assertForbidden()
            ->assertJsonPath('message', 'The data workspace is for administrators. Your work lives in the portal.');

        $this->getJson('/api/development/portal/_grid/employees', $headers)->assertForbidden();
    }

    public function test_a_project_admin_sees_private_columns_locked_rather_than_missing(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', $member->id)->update(['role' => 'ProjectAdmin']);
        $headers = $this->headersFor($member->fresh());

        $response = $this->getJson('/api/development/portal/_grid/employees?per_page=5&cols=all', $headers);
        $response->assertOk();

        // The column is on screen so the grid is not silently hiding it, but no value
        // and no probe of one ever leaves PHP.
        $this->assertSame(['locked' => true], $response->json('data.0.bank_account_number'));
        $this->assertSame(['locked' => true], $response->json('data.0.tax_identification_no'));
        $this->assertStringNotContainsString('bank_account_number`', (string) $response->json('sql'));

        $schema = $this->getJson('/api/development/portal/_schema/employees', $headers);
        $schema->assertOk()->assertJsonPath('editable', false);

        $fields = collect($schema->json('fields'))->keyBy('name');
        $this->assertTrue($fields['sss_no']['locked']);
        $this->assertFalse($fields['sss_no']['sortable']);
        $this->assertFalse($fields['id_no']['locked']);

        // A secret is absent at every tier, never merely locked.
        $this->assertArrayNotHasKey('password_hash', $fields->all());
        $this->assertNotContains('sss_no', $schema->json('presets.key'));
    }

    public function test_a_locked_column_cannot_be_filtered_or_sorted_back_into_view(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', $member->id)->update(['role' => 'ProjectAdmin']);
        $headers = $this->headersFor($member->fresh());

        $response = $this->getJson(
            '/api/development/portal/_grid/employees?per_page=5&filter[bank_account_number]=filled&sort=sss_no:desc',
            $headers,
        );

        $response->assertOk()
            ->assertJsonPath('meta.filtered', false)
            ->assertJsonPath('meta.sort', 'id:asc');

        $this->assertSame(
            $response->json('meta.total'),
            $response->json('meta.unfiltered_total'),
            'A locked column narrowed the page, which would leak which rows hold a value.',
        );
    }

    public function test_the_estimator_resolves_links_without_any_foreign_keys(): void
    {
        if (! (bool) config('products.catalog.project-estimator.enabled')) {
            $this->markTestSkipped('estimator database is not enabled');
        }

        $schema = $this->getJson('/api/development/project-estimator/_schema/projects', $this->authHeaders());
        $schema->assertOk();

        $names = array_column($schema->json('fields'), 'name');

        // employees_projects carries a role qualifier, so Airtable's PM ID, Project
        // Members and Support Members come back as three separate link fields.
        $this->assertContains('link__employees__pm', $names);
        $this->assertContains('link__employees__member', $names);

        $grid = $this->getJson(
            '/api/development/project-estimator/_grid/projects?per_page=10&cols=id,project_name,link__employees__pm',
            $this->authHeaders(),
        );
        $grid->assertOk();

        $labels = [];
        foreach ($grid->json('data') as $row) {
            foreach ($row['link__employees__pm']['chips'] as $chip) {
                $labels[] = $chip['label'];
            }
        }

        $this->assertNotEmpty($labels, 'No PM chip resolved on the estimator.');
        // The Airtable screenshot shows employee id numbers as the chip label.
        $this->assertMatchesRegularExpression('/^\d{6}-\d{4}$/', $labels[0]);
    }

    public function test_the_grid_requires_a_session(): void
    {
        $this->getJson('/api/development/portal/_grid/projects')->assertUnauthorized();
    }

    /**
     * @param  list<string>  $queries
     */
    private function countContaining(array $queries, string $needle): int
    {
        $count = 0;
        foreach ($queries as $query) {
            if (str_contains($query, $needle)) {
                $count++;
            }
        }

        return $count;
    }
}
