<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The Activity tab of a project (docs/power/view-projects-dashboard-plan.md, step 3): every report
 * filed on it, newest first, and the people behind them. Whoever View Projects lets open the project
 * may open it; only the project's lead, a Project Manager covering its department and Admin or higher
 * see everyone's hours and remarks. Anyone else sees who reported, and their own reports in full.
 * What a viewer may not see is left out of the response, never sent and hidden.
 */
final class PortalProjectActivity
{
    public const PER_PAGE = 25;

    public function __construct(private readonly PortalProjectApproval $approval) {}

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(Employee $actor, Request $request, int $projectId): array
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'person' => ['nullable', 'integer'],
            'activity' => ['nullable', 'string', 'max:20'],
        ]);
        $project = $this->connection()->table('projects')->where('id', $projectId)->whereNull('deleted_at')
            ->first(['id', 'department', 'project_lead_email']);
        if ($project === null) {
            abort(404, 'That project was not found.');
        }
        $memberIds = $this->connection()->table('employees_projects')->where('project_id', $projectId)
            ->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $actorId = (int) $actor->getKey();
        $isLead = self::leads($actor->email, $project->project_lead_email);
        if (! $this->canOpen($actor, $request, $project, in_array($actorId, $memberIds, true) || $isLead)) {
            abort(403, 'View Projects does not reach this project.');
        }
        $seesEveryone = $isLead
            || $this->approval->covers($actor, $request, $project->department)
            || PortalRole::isAdmin($actor->role, $actor->role_level)
            || PortalPreview::actsAsDeveloper($actor, $request);

        $rows = PortalProjectWork::reports([$projectId])
            ->addSelect(['report.remarks', 'report.late_submission', 'author_person.first_name', 'author_person.last_name'])
            ->leftJoin('employees as author_person', 'author_person.id', '=', 'author.employee_id')
            ->orderByDesc('work_day')
            ->orderByDesc('report.id')
            ->get()
            ->all();
        $hasLines = PortalProjectWork::isEngineering($project->department);
        $visible = static fn (object $row): bool => $seesEveryone || (int) $row->employee_id === $actorId;

        $authorIds = [];
        foreach ($rows as $row) {
            if ($row->employee_id !== null) {
                $authorIds[] = (int) $row->employee_id;
            }
        }
        $authorPhotos = PortalEmployeePresenter::photoUrls($authorIds);

        $feed = array_values(array_filter($rows, static function (object $row) use ($visible, $filters): bool {
            return $visible($row)
                && (! isset($filters['person']) || (int) $row->employee_id === (int) $filters['person'])
                && (! isset($filters['activity']) || trim((string) $row->code) === $filters['activity']);
        }));
        $page = (int) ($filters['page'] ?? 1);

        return [
            'section' => 'projects',
            'resource' => 'activity',
            'data' => [
                'seesEveryone' => $seesEveryone,
                'people' => $this->people($rows, $memberIds, $project, $visible),
                'activities' => $this->activities(array_filter($rows, $visible)),
                'reports' => array_map(
                    fn (object $row): array => $this->report($row, $hasLines, $authorPhotos),
                    array_slice($feed, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                ),
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'total' => count($feed),
            ],
        ];
    }

    // The same reach the board uses: all, the actor's department plus their own, or only their own.
    private function canOpen(Employee $actor, Request $request, object $project, bool $isOwn): bool
    {
        $reach = PortalRules::active($actor, $request)['projects.view'] ?? null;
        if ($reach === null) {
            return false;
        }
        if ($reach === 'all' || $isOwn) {
            return true;
        }
        $own = PortalPreview::department($actor->department);

        return $reach === 'department' && $own !== '' && $own === PortalPreview::department($project->department);
    }

    /**
     * Everyone who reported, then members who never did; hours, report count and last report only
     * where the viewer may see that person's work.
     *
     * @param  list<object>  $rows
     * @param  list<int>  $memberIds
     * @param  callable(object): bool  $visible
     * @return list<array<string, mixed>>
     */
    private function people(array $rows, array $memberIds, object $project, callable $visible): array
    {
        $people = [];
        foreach ($rows as $row) {
            if ($row->employee_id === null) {
                continue;
            }
            $id = (int) $row->employee_id;
            $people[$id] ??= ['row' => $row, 'hours' => 0.0, 'reports' => 0, 'lastReportOn' => (string) $row->work_day];
            $people[$id]['hours'] += is_numeric($row->hours_rendered) ? (float) $row->hours_rendered : 0.0;
            $people[$id]['reports']++;
        }

        $ids = array_values(array_unique([...array_keys($people), ...$memberIds]));
        $names = $this->connection()->table('employees')->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');
        $photos = PortalEmployeePresenter::photoUrls($ids);
        $list = [];
        foreach ($ids as $id) {
            $person = $names[$id] ?? null;
            $work = $people[$id] ?? null;
            $shown = $work !== null && $visible($work['row']);
            $list[] = [
                'id' => (string) $id,
                'name' => $person === null ? '' : trim($person->first_name.' '.$person->last_name),
                'photoUrl' => $photos[$id] ?? null,
                'isMember' => in_array($id, $memberIds, true),
                'isLead' => $person !== null && self::leads($person->email, $project->project_lead_email),
                'hasReported' => $work !== null,
                'hours' => $shown ? round($work['hours'], 2) : null,
                'reports' => $shown ? $work['reports'] : null,
                'lastReportOn' => $shown ? $work['lastReportOn'] : null,
            ];
        }
        usort($list, static fn (array $a, array $b): int => [$b['hasReported'], $b['hours'] ?? -1, $a['name']] <=> [$a['hasReported'], $a['hours'] ?? -1, $b['name']]);

        return $list;
    }

    /**
     * The activity codes in the reports the viewer may see, for the feed's filter.
     *
     * @param  array<int, object>  $rows
     * @return list<array{code: string, name: string}>
     */
    private function activities(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row->code ?? ''));
            if ($code !== '') {
                $codes[$code] ??= ['code' => $code, 'name' => PortalProjectWork::activityName($row->name ?? null, $code)];
            }
        }
        ksort($codes);

        return array_values($codes);
    }

    /**
     * @param  array<int, string>  $authorPhotos
     * @return array<string, mixed>
     */
    private function report(object $row, bool $hasLines, array $authorPhotos): array
    {
        $code = trim((string) ($row->code ?? ''));

        return [
            'id' => (string) (int) $row->report_id,
            'workDay' => (string) $row->work_day,
            'personId' => $row->employee_id === null ? null : (string) (int) $row->employee_id,
            'personName' => trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
            'personPhotoUrl' => $row->employee_id === null ? null : ($authorPhotos[(int) $row->employee_id] ?? null),
            'hours' => is_numeric($row->hours_rendered) ? round((float) $row->hours_rendered, 2) : 0.0,
            'activityCode' => $code === '' ? null : $code,
            'activityName' => $code === '' ? null : PortalProjectWork::activityName($row->name ?? null, $code),
            'lines' => $hasLines && is_numeric($row->change_in_elements) ? (int) round((float) $row->change_in_elements) : null,
            'remarks' => is_string($row->remarks) && trim($row->remarks) !== '' ? trim($row->remarks) : null,
            'isLate' => strtoupper(trim((string) $row->late_submission)) === 'YES',
        ];
    }

    // The lead is matched by email, never by name.
    private static function leads(mixed $email, mixed $leadEmail): bool
    {
        $lead = is_string($leadEmail) ? strtolower(trim($leadEmail)) : '';

        return $lead !== '' && is_string($email) && strtolower(trim($email)) === $lead;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
