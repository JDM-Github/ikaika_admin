<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The relational half of the workspace: chips that resolve, and questions asked
 * through a relationship rather than about one column.
 */
class WorkspaceRelationshipsTest extends TestCase
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

    public function test_a_link_filter_narrows_a_table_by_who_is_linked_to_it(): void
    {
        $link = DB::connection('portal')->table('employees_projects')->where('role_on_project', 'member')->first();
        $this->assertNotNull($link);

        $expected = DB::connection('portal')->table('employees_projects')
            ->where('employee_id', $link->employee_id)
            ->where('role_on_project', 'member')
            ->distinct()
            ->count('project_id');

        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[link__employees__member]=eq:'.$link->employee_id,
            $this->authHeaders(),
        );

        $response->assertOk()
            ->assertJsonPath('meta.filtered', true)
            ->assertJsonPath('meta.total', $expected);

        $this->assertGreaterThan($expected, $response->json('meta.unfiltered_total'));

        // Every row on the page really carries that person as a member.
        foreach ($response->json('data') as $row) {
            $ids = array_column($row['link__employees__member']['chips'], 'id');
            $this->assertContains((int) $link->employee_id, array_map('intval', $ids));
        }
    }

    public function test_a_link_filter_finds_the_records_with_no_link_at_all(): void
    {
        $orphans = DB::connection('portal')->table('projects')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('projects_clients as x')->whereColumn('x.project_id', 'projects.id');
            })
            ->count();
        $this->assertGreaterThan(0, $orphans, 'Every project has a client, so the empty case is untested.');

        $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[link__clients]=empty',
            $this->authHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('meta.total', $orphans);

        $filled = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[link__clients]=filled',
            $this->authHeaders(),
        );

        $filled->assertOk();
        $this->assertSame(
            $filled->json('meta.unfiltered_total') - $orphans,
            $filled->json('meta.total'),
            'empty and filled do not partition the table between them.',
        );
    }

    public function test_a_link_filter_can_match_the_name_of_the_linked_record(): void
    {
        $client = DB::connection('portal')->table('clients')
            ->whereNotNull('name')->where('name', '!=', '')->first();
        $this->assertNotNull($client);

        $fragment = mb_substr((string) $client->name, 0, 4);

        $expected = DB::connection('portal')->table('projects')
            ->whereExists(function ($query) use ($fragment): void {
                $query->selectRaw('1')
                    ->from('projects_clients as j')
                    ->join('clients as t', 't.id', '=', 'j.client_id')
                    ->whereColumn('j.project_id', 'projects.id')
                    ->where('t.name', 'like', '%'.$fragment.'%');
            })
            ->count();

        $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[link__clients]=contains:'.urlencode($fragment),
            $this->authHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('meta.total', $expected);
    }

    public function test_an_unsupported_operator_on_a_link_is_dropped_rather_than_guessed_at(): void
    {
        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=5&filter[link__clients]=gte:4',
            $this->authHeaders(),
        );

        $response->assertOk()->assertJsonPath('meta.filtered', false);
        $this->assertSame($response->json('meta.unfiltered_total'), $response->json('meta.total'));
    }

    public function test_a_chip_cell_reports_the_full_count_behind_its_overflow(): void
    {
        config(['workspace.chips_per_cell' => 1]);

        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=25&cols=id,link__employees__member',
            $this->authHeaders(),
        );
        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $cell = $row['link__employees__member'];
            $this->assertSame('junction', $cell['kind']);
            $this->assertSame(count($cell['chips']) + $cell['more'], $cell['count']);
        }
    }

    public function test_a_multi_value_child_table_is_a_chip_column_on_its_parent(): void
    {
        // bim_form_elements_included is a multipleSelects field turned into rows: one
        // link back to the parent and no key of its own.
        $schema = $this->getJson('/api/development/portal/_schema/bim_form', $this->authHeaders());
        $schema->assertOk();

        $fields = collect($schema->json('fields'))->keyBy('name');
        $this->assertArrayHasKey('link__bim_form_elements_included', $fields->all());

        $link = $fields['link__bim_form_elements_included'];
        $this->assertSame('link', $link['type']);
        $this->assertSame('child', $link['kind']);
        $this->assertSame('Elements Included', $link['label']);
        $this->assertNull($link['via']);
    }

    public function test_a_foreign_key_column_becomes_a_reference_chip_in_its_own_place(): void
    {
        $schema = $this->getJson('/api/development/portal/_schema/bim_form_elements_included', $this->authHeaders());
        $schema->assertOk();

        $fields = collect($schema->json('fields'));
        $reference = $fields->firstWhere('name', 'bim_form_id');

        $this->assertNotNull($reference);
        $this->assertSame('reference', $reference['type']);
        $this->assertSame('bim_form', $reference['target']);
        $this->assertSame('Bim Form', $reference['label']);

        // It stays one column: the chip replaces the integer rather than joining it,
        // so the column is still the one that sorts and filters.
        $this->assertTrue($reference['sortable']);
        $this->assertSame(1, $fields->where('name', 'bim_form_id')->count());
    }

    public function test_the_key_preset_keeps_room_for_scalars_beside_the_chips(): void
    {
        // employees carries eight link columns; unchecked they filled the whole preset
        // and pushed the name a reader came for off the screen.
        $preset = $this->getJson('/api/development/portal/_schema/employees', $this->authHeaders())
            ->assertOk()
            ->json('presets.key');

        $links = array_filter($preset, fn (string $name): bool => str_starts_with($name, 'link__'));
        $this->assertLessThanOrEqual(3, count($links));
        $this->assertGreaterThan(count($links), count($preset) - count($links));

        // A link Airtable named is preferred over a self-join nobody asked for.
        $this->assertContains('link__projects__member', $preset);
    }

    public function test_an_in_filter_matches_any_of_several_values(): void
    {
        $wanted = ['CAD-to-BIM', 'Scan-to-BIM'];
        $expected = DB::connection('portal')->table('projects')->whereIn('type_of_job', $wanted)->count();
        $this->assertGreaterThan(0, $expected);

        $response = $this->getJson(
            '/api/development/portal/_grid/projects?per_page=50&filter[type_of_job]=in:'.urlencode(implode('|', $wanted)),
            $this->authHeaders(),
        );

        $response->assertOk()->assertJsonPath('meta.total', $expected);

        foreach ($response->json('data') as $row) {
            $this->assertContains($row['type_of_job']['v'], $wanted);
        }
    }
}
