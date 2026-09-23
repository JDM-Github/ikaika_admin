<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
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

    public const CACHE_VERSION_KEY = 'portal:home:version';

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
        $portfolioCompletion = $this->portfolioCompletion($activeProjects);

        return [
            'section' => 'home',
            'resource' => 'dashboard',
            'data' => [
                'monthKey' => $monthKey,
                'monthHours' => round((float) ($counts['hours'] ?? 0), 2),
                'monthReportCount' => (int) ($counts['reports'] ?? 0),
                'activeProjectCount' => $activeCount,
                'portfolioCompletion' => $portfolioCompletion,
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
     * @return list<array{id: string, code: string, name: string, departmentName: string, completionRatio: float}>
     */
    private function activeProjects(int $employeeId): array
    {
        $rows = $this->connection()
            ->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employeeId)
            ->select([
                'projects.id',
                'projects.project_number',
                'projects.project_name',
                'projects.department',
                'projects.status',
                'projects.progress_pct',
            ])
            ->distinct()
            ->get();

        $projects = [];
        foreach ($rows as $row) {
            if (! $this->isActiveStatus($row->status ?? null)) {
                continue;
            }
            $id = (string) (int) $row->id;
            $code = trim((string) ($row->project_number ?? ''));
            $name = trim((string) ($row->project_name ?? ''));
            // Some rows store "260133 JMH Clinical Lab" only in project_name.
            if ($code === '' && preg_match('/^(\d+)\s+(.+)$/', $name, $match) === 1) {
                $code = $match[1];
                $name = trim($match[2]);
            }
            $department = trim((string) ($row->department ?? ''));
            $projects[$id] = [
                'id' => $id,
                'code' => $code !== '' ? $code : $id,
                'name' => $name !== '' ? $name : 'Project',
                'departmentName' => $department !== '' ? $department : 'Unassigned',
                'completionRatio' => $this->completionRatio($row->progress_pct ?? null),
            ];
        }

        return array_values($projects);
    }

    /**
     * @param  list<array{id: string, code: string, name: string, departmentName: string, completionRatio: float}>  $projects
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
     * @param  list<array{id: string, code: string, name: string, departmentName: string, completionRatio: float}>  $projects
     * @return list<array{id: string, code: string, name: string, departmentName: string, completionRatio: float}>
     */
    private function trackedProjects(array $projects): array
    {
        usort($projects, static fn (array $first, array $second): int => $first['completionRatio'] <=> $second['completionRatio']);

        return array_slice($projects, 0, self::TRACKED_PROJECT_LIMIT);
    }

    /**
     * @param  list<array{completionRatio: float}>  $projects
     */
    private function portfolioCompletion(array $projects): float
    {
        if ($projects === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($projects as $project) {
            $total += $project['completionRatio'];
        }

        return round($total / count($projects), 4);
    }

    private function isActiveStatus(mixed $status): bool
    {
        $value = strtolower(trim((string) $status));
        if ($value === '') {
            return true;
        }

        return ! in_array($value, self::INACTIVE_STATUSES, true);
    }

    private function completionRatio(mixed $progressPct): float
    {
        if ($progressPct === null || $progressPct === '') {
            return 0.0;
        }
        $value = (float) $progressPct;
        if ($value < 0) {
            return 0.0;
        }
        // Airtable stored whole percents; a 0..1 ratio is already finished.
        if ($value > 1) {
            $value = $value / 100;
        }

        return round(min(1.0, $value), 4);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
