<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectRepositories;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The rules Add Project gained on 2026-10-06, on top of PortalProjectEditor: a project is numbered
 * YY + client code + counter; no two live projects share a name; an Engineering project needs its
 * area, every level of detail and its links; an MIS project tracks Bitbucket repositories; anyone may
 * add one; and View Projects' Project Team says who leads, who is a member and who supports.
 */
class PortalProjectNumbersTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/projects';

    private const LINKS = [
        'forma' => 'https://autodeskforma.com/project/1',
        'ms-teams' => 'https://teams.microsoft.com/l/channel/1',
        'ms-planner' => 'https://tasks.office.com/plan/1',
        'ms-loop' => 'https://loop.microsoft.com/p/1',
        'panoramic' => 'https://panoramic.example.com/tour/1',
        'google-drive' => '/Shared drives/Projects/Alpha',
    ];

    /**
     * @var array<int, array<string, string>>
     */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // The reported ask: YY-<client code>-<counter>, e.g. 260202.
    public function test_a_project_is_numbered_by_year_client_and_counter_and_keeps_it(): void
    {
        $admin = $this->engineeringAdmin();
        $client = $this->client();
        $prefix = Carbon::now('Asia/Manila')->format('y').$client->client_id;
        $expected = collect($this->getJson(self::BASE.'/options', $this->headersOf($admin))->assertOk()->json('data.clients'))
            ->firstWhere('id', (string) $client->id)['nextNumber'];
        $this->assertMatchesRegularExpression('/^'.$prefix.'\d{2}$/', $expected);

        $first = $this->create($admin, ['name' => 'Alpha '.Str::random(6)])->assertCreated()
            ->assertJsonPath('data.number', $expected)
            ->json('data');
        $this->assertStringNotContainsString($expected, $first['name']);
        $row = DB::connection('portal')->table('projects')->where('id', $first['id'])->first();
        $this->assertSame([$expected, $expected.' '.$first['name']], [$row->project_number, $row->project_name]);

        // A deleted project keeps its number taken.
        DB::connection('portal')->table('projects')->where('id', $first['id'])->update(['deleted_at' => now()]);
        $second = $this->create($admin, ['name' => 'Beta '.Str::random(6)])->assertCreated()->json('data.number');
        $this->assertSame($prefix.sprintf('%02d', (int) substr($expected, 4) + 1), $second);

        // A number found only in an older project's name counts, and the highest counter wins.
        DB::connection('portal')->table('projects')->insert([
            ['project_name' => $prefix.'70 Legacy Seventy '.Str::random(4), 'project_number' => null],
            ['project_name' => $prefix.'60 Legacy Sixty '.Str::random(4), 'project_number' => null],
        ]);
        $this->assertSame($prefix.'71', $this->create($admin, ['name' => 'Zeta '.Str::random(6)])->assertCreated()->json('data.number'));

        // Editing renames it and moves it to another client; the number stays.
        $id = DB::connection('portal')->table('projects')->where('project_number', $second)->value('id');
        $otherClient = DB::connection('portal')->table('clients')->where('id', '<>', $client->id)->where('client_id', 'regexp', '^[0-9]{2}$')->value('id');
        $this->patchJson(self::BASE.'/'.$id, $this->body(['name' => 'Gamma '.Str::random(6), 'clientId' => (int) $otherClient]), $this->headersOf($admin))
            ->assertOk()
            ->assertJsonPath('data.number', $second);
        $this->assertStringStartsWith($second.' Gamma ', DB::connection('portal')->table('projects')->where('id', $id)->value('project_name'));
    }

    // The reported ask: no duplicate project names within a department (nj, 2026-10-07: two departments may share one).
    public function test_a_name_already_used_is_refused_whatever_its_case_spacing_or_number(): void
    {
        $admin = $this->engineeringAdmin();
        $name = 'Delta Plant '.Str::random(6);
        $id = $this->create($admin, ['name' => $name])->assertCreated()->json('data.id');

        $this->create($admin, ['name' => '  '.strtoupper(str_replace(' ', '   ', $name))])->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Engineering already has a project named "'));
        // A project from Airtable, its number leading the name, in the same department.
        $legacy = (string) DB::connection('portal')->table('projects')->whereNull('deleted_at')->whereNull('project_number')
            ->where('department', 'Engineering')
            ->where('project_name', 'regexp', '^[0-9]{5,6} [A-Za-z]')->value('project_name');
        $this->create($admin, ['name' => preg_replace('/^\d+\s+/', '', $legacy)])->assertStatus(422);
        $this->create($admin, ['name' => '260299 Epsilon'])->assertStatus(422)
            ->assertJsonPath('message', 'Leave the project number out of the name; it is added when the project is saved.');

        // A project saved under its own name is no clash, and the form reads its name without the number.
        $this->patchJson(self::BASE.'/'.$id, $this->body(['name' => $name]), $this->headersOf($admin))->assertOk()->assertJsonPath('data.name', $name);
        // Once deleted, its name is free again.
        DB::connection('portal')->table('projects')->where('id', $id)->update(['deleted_at' => now()]);
        $this->create($admin, ['name' => $name])->assertCreated();
    }

    // The reported ask: Engineering needs its area above 0 and every level of detail.
    public function test_an_engineering_project_needs_an_area_and_every_level(): void
    {
        $admin = $this->engineeringAdmin();

        $this->create($admin, ['area' => 0])->assertStatus(422)
            ->assertJsonPath('message', 'An Engineering project needs its area in square feet, above 0.');
        $this->create($admin, ['area' => null])->assertStatus(422);
        $this->create($admin, ['levels' => [...$this->levels(), 'plumbing' => null, 'fire-protection' => null]])->assertStatus(422)
            ->assertJsonPath('message', 'An Engineering project needs a level of detail for Plumbing and Fire Protection.');
        $this->create($admin)->assertCreated();
    }

    // Engineering needs all six links; another department's are optional (2026-10-06).
    public function test_only_an_engineering_project_needs_its_links(): void
    {
        $admin = $this->engineeringAdmin();

        $this->create($admin, ['links' => [...self::LINKS, 'ms-loop' => ' ', 'panoramic' => '']])->assertStatus(422)
            ->assertJsonPath('message', 'An Engineering project needs its links. Add MS Loop and Panoramic Photos.');

        $mis = $this->misLead();
        $this->create($mis, ['department' => 'MIS', 'leadId' => (int) $mis->getKey(), 'area' => null, 'levels' => [], 'links' => ['ms-teams' => 'teams.microsoft.com/x']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Each link must start with http:// or https://.');
        $this->create($mis, ['department' => 'MIS', 'leadId' => (int) $mis->getKey(), 'area' => null, 'levels' => [], 'links' => []])
            ->assertCreated();
    }

    // An MIS project takes its repositories as workspace/repository, a bitbucket.org link or an SSH address.
    public function test_an_mis_project_keeps_its_bitbucket_repositories_once_each(): void
    {
        $mis = $this->misLead();
        $misProject = ['department' => 'MIS', 'leadId' => (int) $mis->getKey(), 'area' => null, 'levels' => [], 'links' => []];

        $created = $this->create($mis, [...$misProject, 'repositories' => [
            'https://bitbucket.org/IKAIKA-web/Portal-Frontend.git',
            'ikaika-web/portal-frontend',
            'git@bitbucket.org:ikaika-web/admin.git',
            ' ',
        ]])->assertCreated();
        $this->assertSame(['ikaika-web/admin', 'ikaika-web/portal-frontend'], $created->json('data.repositories'));
        $id = (int) $created->json('data.id');
        $this->assertSame(2, DB::connection('portal')->table('projects_bitbucket_repositories')->where('project_id', $id)->count());

        // Saving the set again replaces it, and the edit's trail says so.
        $this->patchJson(self::BASE.'/'.$id, $this->body([...$misProject, 'name' => $created->json('data.name'), 'repositories' => ['ikaika-web/admin']]), $this->headersOf($mis))
            ->assertOk()
            ->assertJsonPath('data.repositories', ['ikaika-web/admin']);
        $this->assertStringContainsString('Repositories changed', (string) DB::connection('portal')->table('projects_action_history')
            ->join('project_action_history', 'project_action_history.id', '=', 'projects_action_history.project_action_id')
            ->where('projects_action_history.project_id', $id)->orderByDesc('project_action_history.id')->value('project_action_history.remarks'));

        $this->create($mis, [...$misProject, 'repositories' => ['not a repository']])->assertStatus(422)
            ->assertJsonPath('message', 'Enter each repository as workspace/repository or its bitbucket.org link.');
    }

    public function test_a_repository_link_may_point_inside_the_repository_but_twenty_is_the_most(): void
    {
        $this->assertSame('ikaika-web/admin', PortalProjectRepositories::normalize('https://bitbucket.org/ikaika-web/admin/src/main/'));
        $this->assertNull(PortalProjectRepositories::normalize('https://github.com/ikaika-web/admin'));
        $this->assertFalse(PortalProjectRepositories::appliesTo('Engineering'));

        $this->assertThrows(
            fn () => PortalProjectRepositories::parse(array_map(static fn (int $i): string => 'ikaika-web/repo-'.$i, range(1, 21))),
            HttpException::class,
        );
    }

    // Only MIS tracks code from Bitbucket; another department's project keeps no repositories.
    public function test_an_engineering_project_keeps_no_repositories(): void
    {
        $created = $this->create($this->engineeringAdmin(), ['repositories' => ['ikaika-web/portal-frontend']])->assertCreated();

        $this->assertSame([], $created->json('data.repositories'));
    }

    // The reported ask: anyone may add a project; one added by a member waits for a Project Manager.
    public function test_a_member_with_no_powers_given_adds_a_project_that_waits(): void
    {
        $member = $this->member();
        foreach (['access_grants', 'access_personal', 'access_toggles', 'access_limits'] as $table) {
            DB::connection('portal')->table($table)->where('employee_id', $member->getKey())->where('permission_key', 'like', 'projects%')->delete();
        }
        Cache::flush();

        $this->getJson(self::BASE.'/options', $this->headersOf($member))->assertOk();
        $this->create($member, ['leadId' => (int) $this->engineeringAdmin()->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.creationStatus', 'pending');
    }

    // The reported ask: Project Team marks the lead, the members and the support members.
    public function test_the_board_lists_the_team_lead_first_then_members_then_support(): void
    {
        $admin = $this->engineeringAdmin();
        $member = $this->member();
        $support = Employee::query()->whereRaw("LOWER(COALESCE(status, '')) = 'active'")->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) <> 'executive'")->where('id', '<>', $member->getKey())->orderBy('id')->firstOrFail();
        $id = $this->create($admin, [
            'memberIds' => [(int) $member->getKey(), (int) $admin->getKey()],
            'supportMemberIds' => [(int) $support->getKey()],
        ])->assertCreated()->json('data.id');

        $row = collect($this->getJson(self::BASE, $this->headersOf($admin))->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame([
            ['name' => $this->nameOf($admin), 'role' => 'lead'],
            ['name' => $this->nameOf($member), 'role' => 'member'],
            ['name' => $this->nameOf($support), 'role' => 'support'],
        ], $row['team']);
        $this->assertTrue($row['numbered']);
        $this->assertStringNotContainsString($row['code'], $row['name']);

        // Someone on the project as both member and support reads as a member, once.
        DB::connection('portal')->table('employees_projects')->insert(['employee_id' => $support->getKey(), 'project_id' => (int) $id, 'role_on_project' => 'member']);
        $plain = DB::connection('portal')->table('projects')->insertGetId(['project_name' => 'Unnumbered '.Str::random(6), 'department' => 'Engineering']);
        // Written straight to the table, so the board's cache does not know yet.
        Cache::flush();
        $board = collect($this->getJson(self::BASE, $this->headersOf($admin))->assertOk()->json('data'));
        $this->assertSame(['name' => $this->nameOf($support), 'role' => 'member'], $board->firstWhere('id', $id)['team'][2]);
        $this->assertCount(3, $board->firstWhere('id', $id)['team']);
        // A project with no number is listed by its id and says so.
        $this->assertFalse($board->firstWhere('id', (string) $plain)['numbered']);
        $this->assertSame((string) $plain, $board->firstWhere('id', (string) $plain)['code']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(Employee $actor, array $overrides = []): TestResponse
    {
        return $this->postJson(self::BASE, $this->body($overrides), $this->headersOf($actor));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Numbered Project '.Str::random(8),
            'leadId' => (int) $this->engineeringAdmin()->getKey(),
            'clientId' => (int) $this->client()->id,
            'department' => 'Engineering',
            'type' => null,
            'area' => 1200,
            'status' => 'ongoing',
            'memberIds' => [],
            'supportMemberIds' => [],
            'levels' => $this->levels(),
            'links' => self::LINKS,
            'scopeCodes' => [],
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function levels(): array
    {
        return [
            'structural' => '300', 'architectural' => '300', 'electrical' => '200',
            'plumbing' => '200', 'mechanical-duct' => '250', 'fire-protection' => 'N/A',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(Employee $employee): array
    {
        return $this->sessions[(int) $employee->getKey()] ??= $this->headersFor($employee);
    }

    private function client(): object
    {
        $client = DB::connection('portal')->table('clients')->where('client_id', 'regexp', '^[0-9]{2}$')->orderBy('id')->first();
        $this->assertNotNull($client, 'No client with a two-digit code.');

        return $client;
    }

    // An admin leads their own department's projects by preset.
    private function engineeringAdmin(): Employee
    {
        return $this->staff('admin', 'engineering');
    }

    private function misLead(): Employee
    {
        return $this->staff('admin', 'mis');
    }

    private function staff(string $role, string $department): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw('LOWER(COALESCE(role, \'\')) = ?', [$role])
            ->whereRaw("LOWER(COALESCE(role_level, '')) <> 'executive'")
            ->whereRaw('LOWER(COALESCE(department, \'\')) = ?', [$department])
            ->whereNotNull('email')->where('email', '<>', '')
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')->first();
        $this->assertNotNull($employee, 'No active '.$role.' in '.$department.' with an email.');

        return $employee;
    }

    private function nameOf(Employee $employee): string
    {
        return trim($employee->first_name.' '.$employee->last_name);
    }
}
