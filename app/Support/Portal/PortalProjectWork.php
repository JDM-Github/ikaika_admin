<?php

namespace App\Support\Portal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The work behind each project, read from its reports (docs/power/view-projects-dashboard-plan.md):
 * hours, people, first and last report, lines for Engineering, and per activity code its hours,
 * people, last report and a status. Never a percentage: nobody records progress, so none is invented.
 *
 * A report counts unless it or its late filing was rejected, on the day the work was done
 * (actual_work_date, else report_date). Every report carries one activity code and one person.
 *
 * An activity is Active while its last report is within the project's rhythm, Idle after it, and
 * Done once the project is. The rhythm is the median gap between the project's last ten report days,
 * doubled for slack and held between three and thirty days; a project with fewer than three report
 * days uses fourteen.
 */
final class PortalProjectWork
{
    public const ACTIVE = 'active';

    public const IDLE = 'idle';

    public const DONE = 'done';

    private const RHYTHM_DAYS = 10;

    private const RHYTHM_MIN = 3;

    private const RHYTHM_MAX = 30;

    private const RHYTHM_DEFAULT = 14;

    private const RECENT_DAYS = 30;

    private const ENGINEERING = 'engineering';

    /**
     * @param  list<object>  $projects  rows with id, department and status
     * @return array<int, array<string, mixed>>
     */
    public function byProject(array $projects, CarbonImmutable $today): array
    {
        if ($projects === []) {
            return [];
        }
        $byId = [];
        foreach ($projects as $project) {
            $byId[(int) $project->id] = $project;
        }

        $reports = [];
        foreach ($this->reportRows(array_keys($byId)) as $row) {
            $reports[(int) $row->project_id][(int) $row->report_id] = $row;
        }

        $work = [];
        foreach ($byId as $id => $project) {
            $work[$id] = $this->summary($project, array_values($reports[$id] ?? []), $today);
        }

        return $work;
    }

    /**
     * @param  list<object>  $reports
     * @return array<string, mixed>
     */
    private function summary(object $project, array $reports, CarbonImmutable $today): array
    {
        // Completed and Closed both end the work; Closed read as Completed until it became its own status.
        $isDone = in_array(PortalViewProjectPresenter::status($project->status ?? null), ['completed', 'closed'], true);
        $hasLines = self::isEngineering($project->department ?? null);
        $recentFrom = $today->subDays(self::RECENT_DAYS - 1)->toDateString();

        $hours = 0.0;
        $recent = 0.0;
        $lines = 0.0;
        $people = [];
        // Items (change in elements) and hours per person, for the project's top member.
        $byPerson = [];
        $days = [];
        $activities = [];
        foreach ($reports as $report) {
            $day = (string) $report->work_day;
            $reportHours = is_numeric($report->hours_rendered) ? (float) $report->hours_rendered : 0.0;
            $hours += $reportHours;
            if ($day >= $recentFrom) {
                $recent += $reportHours;
            }
            if ($hasLines && is_numeric($report->change_in_elements)) {
                $lines += (float) $report->change_in_elements;
            }
            if ($report->employee_id !== null) {
                $people[(int) $report->employee_id] = true;
                $byPerson[(int) $report->employee_id] ??= ['items' => 0.0, 'hours' => 0.0];
                $byPerson[(int) $report->employee_id]['hours'] += $reportHours;
                if ($hasLines && is_numeric($report->change_in_elements)) {
                    $byPerson[(int) $report->employee_id]['items'] += (float) $report->change_in_elements;
                }
            }
            $days[$day] = true;

            $code = trim((string) ($report->code ?? ''));
            if ($code === '') {
                continue;
            }
            $activities[$code] ??= ['code' => $code, 'name' => self::activityName($report->name ?? null, $code), 'hours' => 0.0, 'reports' => 0, 'people' => [], 'lastReportOn' => $day];
            $activities[$code]['hours'] += $reportHours;
            $activities[$code]['reports']++;
            if ($report->employee_id !== null) {
                $activities[$code]['people'][(int) $report->employee_id] = true;
            }
            if ($day > $activities[$code]['lastReportOn']) {
                $activities[$code]['lastReportOn'] = $day;
            }
        }

        $sortedDays = array_keys($days);
        sort($sortedDays);
        $rhythm = self::rhythm($sortedDays);
        $rows = [];
        foreach ($activities as $activity) {
            $sinceLast = CarbonImmutable::parse($activity['lastReportOn'])->diffInDays($today, false);
            $rows[] = [
                'code' => $activity['code'],
                'name' => $activity['name'],
                'hours' => round($activity['hours'], 2),
                'reports' => $activity['reports'],
                'people' => count($activity['people']),
                'lastReportOn' => $activity['lastReportOn'],
                'status' => $isDone ? self::DONE : ($sinceLast <= $rhythm ? self::ACTIVE : self::IDLE),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['hours'], $a['code']] <=> [$a['hours'], $b['code']]);

        return [
            'hoursTotal' => round($hours, 2),
            'hoursLast30Days' => round($recent, 2),
            'reporterCount' => count($people),
            'firstReportOn' => $sortedDays[0] ?? null,
            'lastReportOn' => $sortedDays === [] ? null : $sortedDays[count($sortedDays) - 1],
            'linesTotal' => $hasLines ? (int) round($lines) : null,
            'rhythmDays' => $rhythm,
            'activities' => $rows,
            'topItems' => $hasLines ? self::topItems($byPerson) : null,
        ];
    }

    /**
     * Who reported the most items on an Engineering project, or null before anyone reported one.
     *
     * @param  array<int, array{items: float, hours: float}>  $byPerson
     * @return array{employeeId: int, items: int, hours: float}|null
     */
    private static function topItems(array $byPerson): ?array
    {
        $top = null;
        foreach ($byPerson as $employeeId => $work) {
            if ($work['items'] > 0 && ($top === null || $work['items'] > $top['items'])) {
                $top = ['employeeId' => $employeeId, 'items' => (int) round($work['items']), 'hours' => round($work['hours'], 2)];
            }
        }

        return $top;
    }

    // Engineering's projects carry lines (change in elements) and the Engineering-only form fields; no other department's do.
    public static function isEngineering(mixed $department): bool
    {
        return PortalPreview::department($department) === self::ENGINEERING;
    }

    /**
     * @param  list<string>  $sortedDays
     */
    private static function rhythm(array $sortedDays): int
    {
        $recent = array_slice($sortedDays, -self::RHYTHM_DAYS);
        if (count($recent) < 3) {
            return self::RHYTHM_DEFAULT;
        }
        $gaps = [];
        for ($index = 1; $index < count($recent); $index++) {
            $gaps[] = CarbonImmutable::parse($recent[$index - 1])->diffInDays(CarbonImmutable::parse($recent[$index]));
        }
        sort($gaps);
        $middle = intdiv(count($gaps), 2);
        $median = count($gaps) % 2 === 1 ? $gaps[$middle] : ($gaps[$middle - 1] + $gaps[$middle]) / 2;

        return (int) max(self::RHYTHM_MIN, min(self::RHYTHM_MAX, (int) ceil($median * 2)));
    }

    // Airtable names repeat the code ("8303- ELECTRICAL REVIT MODELING"); the code is sent on its own.
    public static function activityName(mixed $name, string $code): string
    {
        $text = trim((string) $name);
        $stripped = trim((string) preg_replace('/^'.preg_quote($code, '/').'\s*-\s*/', '', $text));

        return $stripped !== '' ? $stripped : $code;
    }

    /**
     * @param  list<int>  $projectIds
     * @return iterable<object>
     */
    private function reportRows(array $projectIds): iterable
    {
        return self::reports($projectIds)->get();
    }

    /**
     * The reports that count on these projects, with their person, activity code and work day; the
     * Activity tab (PortalProjectActivity) reads the same rows.
     *
     * @param  list<int>  $projectIds
     */
    public static function reports(array $projectIds): Builder
    {
        return Model::getConnectionResolver()->connection('portal')
            ->table('projects_user_reports as link')
            ->join('user_reports as report', 'report.id', '=', 'link.user_report_id')
            ->leftJoin('employees_user_reports as author', 'author.user_report_id', '=', 'report.id')
            ->leftJoin('user_reports_activity_codes as report_code', 'report_code.user_report_id', '=', 'report.id')
            ->leftJoin('activity_codes as code', 'code.id', '=', 'report_code.activity_code_id')
            ->whereIn('link.project_id', $projectIds)
            ->whereRaw("COALESCE(report.approval, '') <> 'Rejected'")
            ->whereRaw("COALESCE(report.late_submission_approval, '') <> 'Rejected'")
            ->select([
                'link.project_id',
                'report.id as report_id',
                'report.hours_rendered',
                'report.change_in_elements',
                'author.employee_id',
                'code.id_no as code',
                'code.name',
            ])
            ->selectRaw('DATE(COALESCE(report.actual_work_date, report.report_date)) as work_day');
    }
}
