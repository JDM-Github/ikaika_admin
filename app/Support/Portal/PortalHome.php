<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Home / Dashboard: one skinny read for the signed-in member's month and active projects.
 *
 * The screen used to pull every project fixture and every submitted report, then aggregate in the
 * client. That is the wrong shape for a dashboard: four KPI tiles do not need a year of timesheets.
 */
final class PortalHome
{
    public const CACHE_TTL_SECONDS = 30;

    public const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    private const RECENT_REPORT_LIMIT = 5;

    private const TRACKED_PROJECT_LIMIT = 5;

    private const REPORT_TREND_LIMIT = 17;

    /**
     * Statuses that leave the active board. Anything else (Started, Ongoing, blank) still needs
     * attention on the dashboard.
     *
     * @var list<string>
     */
    private const INACTIVE_STATUSES = [
        'closed',
        'done',
        'completed',
        'cancelled',
        'canceled',
        'on hold',
        'on-hold',
        'hold',
        'archived',
    ];

    public function __construct(
        private readonly PortalTimezone $timezone,
        private readonly PortalSubmittedReports $reports,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array<string, mixed>
     * }
     */
    public function show(Employee $actor, Request $request): array
    {
        $today = $this->timezone->today();
        $from = $today->copy()->startOfMonth()->toDateString();
        $to = $today->toDateString();
        $monthKey = $today->format('Y-m');

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:home:'.$version.':'.$actor->getKey().':'.$monthKey;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor, $request, $from, $to, $monthKey): array {
            return $this->build($actor, $request, $from, $to, $monthKey);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array<string, mixed>
     * }
     */
    private function build(Employee $actor, Request $request, string $from, string $to, string $monthKey): array
    {
        $rangeRequest = Request::create($request->url(), 'GET', [
            'from' => $from,
            'to' => $to,
        ]);
        $rangeRequest->headers->replace($request->headers->all());

        $reportsPayload = $this->reports->list($actor, $rangeRequest);
        /** @var list<array<string, mixed>> $reportGroups */
        $reportGroups = $reportsPayload['data'] ?? [];
        $counts = $reportsPayload['counts'] ?? [];

        $activeProjects = $this->activeProjects((int) $actor->getKey());
        $activeCount = count($activeProjects);

        return [
            'section' => 'home',
            'resource' => 'dashboard',
            'data' => [
                'monthKey' => $monthKey,
                'monthHours' => round((float) ($counts['hours'] ?? 0), 2),
                'monthReportCount' => (int) ($counts['reports'] ?? 0),
                'activeProjectCount' => $activeCount,
                'reportTrend' => $this->reportTrend($reportGroups),
                'projectMix' => $this->projectMix($activeProjects),
                'recentReports' => array_slice($reportGroups, 0, self::RECENT_REPORT_LIMIT),
                'trackedProjects' => $this->trackedProjects($activeProjects),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reportGroups
     * @return list<array{date: string, hours: float, reportCount: int}>
     */
    private function reportTrend(array $reportGroups): array
    {
        $daily = [];
        foreach ($reportGroups as $group) {
            $date = trim((string) ($group['submittedOn'] ?? ''));
            if ($date === '') {
                continue;
            }
            $day = substr($date, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
                continue;
            }
            $hours = 0.0;
            foreach ($group['entries'] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $hours += (float) ($entry['hoursRendered'] ?? 0);
            }
            if (! isset($daily[$day])) {
                $daily[$day] = ['date' => $day, 'hours' => 0.0, 'reportCount' => 0];
            }
            $daily[$day]['hours'] = round($daily[$day]['hours'] + $hours, 2);
            $daily[$day]['reportCount']++;
        }

        ksort($daily);

        return array_values(array_slice($daily, -self::REPORT_TREND_LIMIT));
    }

    /**
     * The member's active projects, each with the work its reports hold (hours and last report)
     * from PortalProjectWork. Never a completion ratio: nobody records progress.
     *
     * @return list<array{
     *     id: string,
     *     code: string,
     *     name: string,
     *     departmentName: string,
     *     hoursTotal: float,
     *     lastReportOn: string|null
     * }>
     */
    private function activeProjects(int $employeeId): array
    {
        $rows = $this->connection()
            ->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employeeId)
            // A deleted project waits in the Recycle Bin, so it is no longer an active project.
            ->whereNull('projects.deleted_at')
            ->select([
                'projects.id',
                'projects.project_number',
                'projects.project_name',
                'projects.department',
                'projects.status',
            ])
            ->distinct()
            ->get();

        $active = [];
        foreach ($rows as $row) {
            if ($this->isActiveStatus($row->status ?? null)) {
                $active[] = $row;
            }
        }

        $today = CarbonImmutable::instance($this->timezone->today());
        $work = (new PortalProjectWork)->byProject($active, $today);

        $projects = [];
        foreach ($active as $row) {
            $id = (string) (int) $row->id;
            $code = trim((string) ($row->project_number ?? ''));
            $name = trim((string) ($row->project_name ?? ''));
            // Some rows store "260133 JMH Clinical Lab" only in project_name.
            if ($code === '' && preg_match('/^(\d+)\s+(.+)$/', $name, $match) === 1) {
                $code = $match[1];
                $name = trim($match[2]);
            }
            $department = trim((string) ($row->department ?? ''));
            $summary = $work[(int) $row->id] ?? null;
            $projects[$id] = [
                'id' => $id,
                'code' => $code !== '' ? $code : $id,
                'name' => $name !== '' ? $name : 'Project',
                'departmentName' => $department !== '' ? $department : 'Unassigned',
                'hoursTotal' => (float) ($summary['hoursTotal'] ?? 0),
                'lastReportOn' => $summary['lastReportOn'] ?? null,
            ];
        }

        return array_values($projects);
    }

    /**
     * @param  list<array{id: string, code: string, name: string, departmentName: string, hoursTotal: float, lastReportOn: string|null}>  $projects
     * @return list<array{key: string, label: string, count: int, ratio: float}>
     */
    private function projectMix(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $counts = [];
        foreach ($projects as $project) {
            $label = $project['departmentName'];
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        $total = count($projects);
        $mix = [];
        foreach ($counts as $label => $count) {
            $mix[] = [
                'key' => $label,
                'label' => $label,
                'count' => $count,
                'ratio' => round($count / $total, 4),
            ];
        }

        usort($mix, static function (array $first, array $second): int {
            $byCount = $second['count'] <=> $first['count'];

            return $byCount !== 0 ? $byCount : strcmp($first['label'], $second['label']);
        });

        return $mix;
    }

    /**
     * The five projects that most need attention: least recently reported first, and a project with
     * no report at all before any of those.
     *
     * @param  list<array{id: string, code: string, name: string, departmentName: string, hoursTotal: float, lastReportOn: string|null}>  $projects
     * @return list<array{id: string, code: string, name: string, departmentName: string, hoursTotal: float, lastReportOn: string|null}>
     */
    private function trackedProjects(array $projects): array
    {
        usort($projects, static fn (array $first, array $second): int => ($first['lastReportOn'] ?? null) <=> ($second['lastReportOn'] ?? null));

        return array_slice($projects, 0, self::TRACKED_PROJECT_LIMIT);
    }

    private function isActiveStatus(mixed $status): bool
    {
        $value = strtolower(trim((string) $status));
        if ($value === '') {
            return true;
        }

        return ! in_array($value, self::INACTIVE_STATUSES, true);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
