<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Reports / Projects: what the daily and late report builders may pick from.
 *
 * Only the signed-in member's own projects, because a report is filed against work they were
 * assigned. Activities are grouped by job type rather than repeated on every project: 45 projects
 * sharing 8 job types would otherwise send the same code list 45 times.
 */
final class PortalReportProjects
{
    public const CACHE_TTL_SECONDS = 30;

    private const CACHE_VERSION_KEY = 'portal:reports:projects:version';

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array{id: string, label: string, jobType: ?string}>,
     *     activityGroups: list<array{jobType: ?string, activities: list<string>}>
     * }
     */
    public function list(Employee $actor): array
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:reports:projects:'.$version.':'.$actor->getKey();

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($actor): array {
            return $this->build((int) $actor->getKey());
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
     *     data: list<array{id: string, label: string, jobType: ?string}>,
     *     activityGroups: list<array{jobType: ?string, activities: list<string>}>
     * }
     */
    private function build(int $employeeId): array
    {
        $projects = $this->ownProjects($employeeId);
        $activities = $this->activityCodes();

        $jobTypes = [];
        foreach ($projects as $project) {
            $jobType = $project['jobType'];
            if ($jobType !== null && ! in_array($jobType, $jobTypes, true)) {
                $jobTypes[] = $jobType;
            }
        }

        $allowed = $this->allowedInitials($jobTypes);
        $groups = [];
        $needsFallback = false;
        foreach ($projects as $project) {
            $jobType = $project['jobType'];
            $initials = $jobType === null ? [] : $allowed[$this->key($jobType)] ?? [];
            // A job type with no rule of its own is not a job type with no activities: the member
            // still has to report the day, so the whole catalogue stands in for the missing list.
            if ($initials === []) {
                $needsFallback = true;

                continue;
            }
            if (isset($groups[$this->key((string) $jobType)])) {
                continue;
            }
            $groups[$this->key((string) $jobType)] = [
                'jobType' => $jobType,
                'activities' => $this->matching($activities, $initials),
            ];
        }

        $activityGroups = array_values($groups);
        if ($needsFallback) {
            $activityGroups[] = [
                'jobType' => null,
                'activities' => array_values(array_map(
                    static fn (array $activity): string => $activity['label'],
                    $activities,
                )),
            ];
        }

        return [
            'section' => 'reports',
            'resource' => 'projects',
            'data' => $projects,
            'activityGroups' => $activityGroups,
        ];
    }

    /**
     * One read over the (employee_id, project_id) primary key. A member sees their own board and
     * nobody else's, whatever role they hold on it.
     *
     * @return list<array{id: string, label: string, jobType: ?string}>
     */
    private function ownProjects(int $employeeId): array
    {
        $rows = $this->connection()
            ->table('employees_projects')
            ->join('projects', 'projects.id', '=', 'employees_projects.project_id')
            ->where('employees_projects.employee_id', $employeeId)
            ->select(['projects.id', 'projects.project_number', 'projects.project_name', 'projects.type_of_job'])
            ->distinct()
            ->get();

        $projects = [];
        foreach ($rows as $row) {
            $jobType = trim((string) ($row->type_of_job ?? ''));
            $projects[(int) $row->id] = [
                'id' => (string) (int) $row->id,
                'label' => PortalSubmittedReportPresenter::projectLabel(
                    $row->project_number ?? null,
                    $row->project_name ?? null,
                ),
                'jobType' => $jobType === '' ? null : $jobType,
            ];
        }

        $ordered = array_values($projects);
        usort($ordered, static fn (array $first, array $second): int => strcmp($first['label'], $second['label']));

        return $ordered;
    }

    /**
     * @return list<array{label: string, code: string}>
     */
    private function activityCodes(): array
    {
        $rows = $this->connection()
            ->table('activity_codes')
            ->select(['name', 'id_no'])
            ->orderBy('id_no')
            ->get();

        $codes = [];
        foreach ($rows as $row) {
            $label = PortalSubmittedReportPresenter::activityLabel($row->name ?? null, $row->id_no ?? null);
            if ($label === 'Unassigned') {
                continue;
            }
            $codes[] = ['label' => $label, 'code' => trim((string) ($row->id_no ?? ''))];
        }

        return $codes;
    }

    /**
     * job_type_allowed_activities stores the leading digits of the codes a job type may use, as
     * one comma-separated string per row.
     *
     * @param  list<string>  $jobTypes
     * @return array<string, list<string>>
     */
    private function allowedInitials(array $jobTypes): array
    {
        if ($jobTypes === []) {
            return [];
        }

        $rows = $this->connection()
            ->table('job_type_allowed_activities')
            ->select(['job_type', 'activity_code_id_initials'])
            ->get();

        $allowed = [];
        foreach ($rows as $row) {
            $jobType = trim((string) ($row->job_type ?? ''));
            if ($jobType === '') {
                continue;
            }
            $initials = [];
            foreach (explode(',', (string) ($row->activity_code_id_initials ?? '')) as $piece) {
                $value = trim($piece);
                if ($value !== '') {
                    $initials[] = $value;
                }
            }
            $allowed[$this->key($jobType)] = $initials;
        }

        return $allowed;
    }

    /**
     * @param  list<array{label: string, code: string}>  $activities
     * @param  list<string>  $initials
     * @return list<string>
     */
    private function matching(array $activities, array $initials): array
    {
        $labels = [];
        foreach ($activities as $activity) {
            foreach ($initials as $prefix) {
                if ($activity['code'] !== '' && str_starts_with($activity['code'], $prefix)) {
                    $labels[] = $activity['label'];

                    break;
                }
            }
        }

        return $labels;
    }

    private function key(string $value): string
    {
        return strtolower(trim($value));
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
