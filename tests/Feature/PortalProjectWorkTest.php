<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalProjectWork;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * A project's work from its reports: hours, people, first and last report, lines only for
 * Engineering, and each activity's status from the project's own rhythm. Rejected reports never
 * count, and a closed project's activities are Done.
 */
class PortalProjectWorkTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const TODAY = '2026-10-01';

    private int $codeA;

    private int $codeB;

    /**
     * @var list<int>
     */
    private array $people;

    protected function setUp(): void
    {
        parent::setUp();
        $portal = DB::connection('portal');
        $this->codeA = (int) $portal->table('activity_codes')->insertGetId(['id_no' => '9801', 'name' => '9801- TEST MODELING', 'department' => 'Engineering']);
        $this->codeB = (int) $portal->table('activity_codes')->insertGetId(['id_no' => '9802', 'name' => 'Test checking', 'department' => 'Engineering']);
        $this->people = $portal->table('employees')->orderBy('id')->limit(3)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function project(string $department = 'Engineering', string $status = 'Started'): object
    {
        $id = (int) DB::connection('portal')->table('projects')->insertGetId(['project_name' => 'Work Test 2020', 'department' => $department, 'status' => $status]);

        return (object) ['id' => $id, 'department' => $department, 'status' => $status];
    }

    private function report(object $project, string $day, float $hours, int $code, int $person, array $extra = []): void
    {
        $portal = DB::connection('portal');
        $id = (int) $portal->table('user_reports')->insertGetId([
            'report_date' => $day,
            'actual_work_date' => $day,
            'hours_rendered' => $hours,
            'change_in_elements' => 10,
            'approval' => 'Approved',
            'late_submission_approval' => 'N/A',
            ...$extra,
        ]);
        $portal->table('projects_user_reports')->insert(['project_id' => $project->id, 'user_report_id' => $id]);
        $portal->table('employees_user_reports')->insert(['employee_id' => $person, 'user_report_id' => $id]);
        $portal->table('user_reports_activity_codes')->insert(['user_report_id' => $id, 'activity_code_id' => $code]);
    }

    /**
     * @return array<string, mixed>
     */
    private function work(object $project): array
    {
        return (new PortalProjectWork)->byProject([$project], CarbonImmutable::parse(self::TODAY))[$project->id];
    }

    public function test_it_sums_the_work_and_leaves_rejected_reports_out(): void
    {
        $project = $this->project();
        [$ana, $ben] = $this->people;
        $this->report($project, '2026-06-01', 8, $this->codeA, $ana);
        $this->report($project, '2026-09-20', 4, $this->codeA, $ben);
        $this->report($project, '2026-09-29', 2.5, $this->codeB, $ana, ['change_in_elements' => 5]);
        $this->report($project, '2026-09-30', 99, $this->codeA, $ben, ['approval' => 'Rejected']);
        $this->report($project, '2026-09-30', 99, $this->codeA, $ben, ['late_submission_approval' => 'Rejected']);

        $work = $this->work($project);

        $this->assertSame(14.5, $work['hoursTotal']);
        $this->assertSame(6.5, $work['hoursLast30Days']);
        $this->assertSame(2, $work['reporterCount']);
        $this->assertSame('2026-06-01', $work['firstReportOn']);
        $this->assertSame('2026-09-29', $work['lastReportOn']);
        $this->assertSame(25, $work['linesTotal']);
        // The top member reported the most items: Ana's 10 and 5 against Ben's 10.
        $this->assertSame(['employeeId' => $ana, 'items' => 15, 'hours' => 10.5], $work['topItems']);
        $this->assertSame(['9801', '9802'], array_column($work['activities'], 'code'));
        $this->assertSame(['code' => '9801', 'name' => 'TEST MODELING', 'hours' => 12.0, 'reports' => 2, 'people' => 2, 'lastReportOn' => '2026-09-20'], array_diff_key($work['activities'][0], ['status' => true]));
    }

    // The board names an Engineering project's top member by items, for everyone who can open it; other departments name none.
    public function test_the_board_names_the_top_member_by_items(): void
    {
        $project = $this->project();
        $general = $this->project('General');
        [$ana, $ben] = $this->people;
        $this->report($project, '2026-09-20', 4, $this->codeA, $ben, ['change_in_elements' => 40]);
        $this->report($project, '2026-09-21', 9, $this->codeA, $ana, ['change_in_elements' => 3]);
        $this->report($general, '2026-09-21', 9, $this->codeA, $ana);
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'access_self_reach', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        $admin = Employee::query()->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')->orderBy('id')->firstOrFail();

        $board = collect($this->getJson('/api/development/portal/projects', $this->headersFor($admin))->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(['id' => (string) $ben, 'measure' => 'items', 'value' => 40, 'hours' => 4, 'commits' => null], array_diff_key($board[(string) $project->id]['topMember'], ['name' => true, 'photoUrl' => true]));
        $this->assertNull($board[(string) $general->id]['topMember']);
    }

    // Lines are Engineering's: an MIS project carries none, and a project without reports reads empty.
    public function test_lines_only_for_engineering_and_nothing_without_reports(): void
    {
        $mis = $this->project('MIS');
        $this->report($mis, '2026-09-29', 3, $this->codeA, $this->people[0]);
        $empty = $this->project();

        $this->assertNull($this->work($mis)['linesTotal']);
        $this->assertSame(['hoursTotal' => 0.0, 'hoursLast30Days' => 0.0, 'reporterCount' => 0, 'firstReportOn' => null, 'lastReportOn' => null, 'linesTotal' => 0, 'rhythmDays' => 14, 'activities' => [], 'topItems' => null], $this->work($empty));
        $this->assertNull($this->work($mis)['topItems']);
    }

    // A project reported every few days goes Idle after a short quiet spell; a closed one is Done.
    public function test_activity_status_follows_the_project_rhythm(): void
    {
        $project = $this->project();
        foreach (['2026-09-10', '2026-09-12', '2026-09-14', '2026-09-16', '2026-09-18'] as $day) {
            $this->report($project, $day, 1, $this->codeA, $this->people[0]);
        }
        $this->report($project, '2026-09-28', 1, $this->codeB, $this->people[1]);

        $work = $this->work($project);
        $statuses = array_column($work['activities'], 'status', 'code');

        // Gaps 2, 2, 2, 2 and 10 days: median 2, doubled to 4.
        $this->assertSame(4, $work['rhythmDays']);
        $this->assertSame(PortalProjectWork::IDLE, $statuses['9801']);
        $this->assertSame(PortalProjectWork::ACTIVE, $statuses['9802']);

        $closed = $this->project('Engineering', 'Closed');
        $this->report($closed, '2026-09-30', 1, $this->codeA, $this->people[0]);
        $this->assertSame(PortalProjectWork::DONE, $this->work($closed)['activities'][0]['status']);
    }
}
