<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Which activities a project may report against: its Type of project's list (job_type_allowed_activities,
 * edited on Project Settings), less the ones taken out for this project alone (2026-10-13, user).
 *
 * A row with a department is that department's list for the type, and wins over the shared row (no
 * department) -- one name such as Management is a type in several departments, each with its own list.
 *
 * A project only ever removes: it cannot add a code its type does not allow, so what it stores is what
 * it took out (projects_removed_activities). A project that removed nothing has its type's whole list,
 * including any code the type gains later.
 */
final class PortalProjectActivities
{
    /**
     * The activity codes each job type allows, keyed by job type. Stored prefixes are resolved to whole
     * codes, so a form shows ticked codes rather than their digits. A type with no rule is absent.
     *
     * @return array<string, list<string>>
     */
    public function allowedByType(): array
    {
        return $this->lists()['shared'];
    }

    /**
     * The department rows, keyed by department then job type, for a type one department lists apart.
     *
     * @return array<string, array<string, list<string>>>
     */
    public function allowedByDepartment(): array
    {
        return $this->lists()['departments'];
    }

    /**
     * A project's whole list: its department's row for its type, else the shared one.
     *
     * @return list<string>
     */
    public function allowedFor(mixed $department, ?string $jobType): array
    {
        if ($jobType === null) {
            return [];
        }
        $lists = $this->lists();
        $key = PortalPreview::department($department);
        foreach ($lists['departments'] as $name => $types) {
            if (PortalPreview::department($name) === $key && isset($types[$jobType])) {
                return $types[$jobType];
            }
        }

        return $lists['shared'][$jobType] ?? [];
    }

    /**
     * Every row's prefixes resolved to whole codes, the shared rows apart from the department ones.
     *
     * @return array{shared: array<string, list<string>>, departments: array<string, array<string, list<string>>>}
     */
    private function lists(): array
    {
        $codes = PortalActivityScopes::all();
        $rows = $this->connection()->table('job_type_allowed_activities')->get(['job_type', 'department', 'activity_code_id_initials']);

        $lists = ['shared' => [], 'departments' => []];
        foreach ($rows as $row) {
            $jobType = trim((string) ($row->job_type ?? ''));
            if ($jobType === '') {
                continue;
            }
            $initials = array_values(array_filter(array_map('trim', explode(',', (string) ($row->activity_code_id_initials ?? ''))), static fn (string $value): bool => $value !== ''));
            $chosen = [];
            foreach ($codes as $code) {
                foreach ($initials as $prefix) {
                    if ($code['code'] !== '' && str_starts_with($code['code'], $prefix)) {
                        $chosen[] = $code['code'];
                        break;
                    }
                }
            }
            $department = trim((string) ($row->department ?? ''));
            if ($department === '') {
                $lists['shared'][$jobType] = $chosen;
            } else {
                $lists['departments'][$department][$jobType] = $chosen;
            }
        }

        return $lists;
    }

    /**
     * The codes a save takes out, kept only where the type allows them: null when the save did not say,
     * so an edit that never opened the list leaves it as it was.
     *
     * @return list<string>|null
     */
    public function validated(mixed $raw, ?string $jobType, mixed $department = null): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw)) {
            abort(422, 'Removed activities must be a list.');
        }
        $allowed = $this->allowedFor($department, $jobType);

        $removed = [];
        foreach ($raw as $value) {
            $code = trim((string) $value);
            if ($code !== '' && in_array($code, $allowed, true) && ! in_array($code, $removed, true)) {
                $removed[] = $code;
            }
        }
        sort($removed);

        return $removed;
    }

    /**
     * @return list<string>
     */
    public function removedFor(int $projectId): array
    {
        return $this->connection()->table('projects_removed_activities')
            ->where('project_id', $projectId)
            ->orderBy('activity_code')
            ->pluck('activity_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }

    /**
     * @param  list<string>  $codes
     */
    public function replace(int $projectId, array $codes): void
    {
        $this->connection()->table('projects_removed_activities')->where('project_id', $projectId)->delete();
        if ($codes === []) {
            return;
        }
        $this->connection()->table('projects_removed_activities')->insert(array_map(
            static fn (string $code): array => ['project_id' => $projectId, 'activity_code' => $code],
            $codes,
        ));
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
