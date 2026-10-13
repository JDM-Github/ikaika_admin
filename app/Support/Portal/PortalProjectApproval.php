<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Who may approve a project, and telling them one waits (Project Management, 2026-10-01).
 *
 * Project Approver (projects.manager) reaches projects as the other project powers do (2026-10-07,
 * supervisor): lead the ones the approver leads, own the ones they are on or added, department their
 * department's plus the ones they lead, all every project. Approving no longer passes the approver's own
 * new projects: only Auto-approve projects (PortalProjectPowers::DIRECT) does that.
 */
final class PortalProjectApproval
{
    public const RULE = 'projects.manager';

    public const APPROVED = 'Approved';

    public const PENDING = 'Pending';

    public const REJECTED = 'Rejected';

    public function __construct(private readonly PortalAudit $audit) {}

    // Whether the actor's Approver reach covers this project row (id, department, lead email, requested_by).
    public function covers(Employee $actor, Request $request, object $project): bool
    {
        return self::reachCovers(PortalRules::reach($actor, $request, self::RULE), $actor, $project);
    }

    // Narrows a projects query to the ones work is filed against: one still waiting for approval, or
    // turned down, takes no reports or requests. Projects from before approvals hold no status at all.
    public static function openForWork(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('projects.project_creation_status')
                ->orWhereNotIn('projects.project_creation_status', [self::PENDING, self::REJECTED]);
        });
    }

    // Narrows a projects query to the rows a reach covers, matching reachCovers.
    public static function scope(Builder $query, Employee $actor, ?string $reach): Builder
    {
        if ($reach === 'all') {
            return $query;
        }
        $email = is_string($actor->email) ? strtolower(trim($actor->email)) : '';
        $leadClause = "LOWER(TRIM(COALESCE(project_lead_email, ''))) = ?";
        if ($reach === 'lead') {
            return $email === '' ? $query->whereRaw('1 = 0') : $query->whereRaw($leadClause, [$email]);
        }
        if ($reach === 'department') {
            $department = PortalPreview::department($actor->department);

            return $query->where(function (Builder $q) use ($department, $email, $leadClause): void {
                $q->whereRaw('1 = 0');
                if ($department !== '') {
                    $q->orWhereRaw('LOWER(TRIM(department)) = ?', [$department]);
                }
                if ($email !== '') {
                    $q->orWhereRaw($leadClause, [$email]);
                }
            });
        }
        if ($reach === 'own') {
            $actorId = (int) $actor->getKey();

            return $query->where(function (Builder $q) use ($actorId): void {
                $q->where('requested_by', $actorId)->orWhereExists(function (Builder $on) use ($actorId): void {
                    $on->selectRaw('1')->from('employees_projects')
                        ->whereColumn('employees_projects.project_id', 'projects.id')
                        ->where('employees_projects.employee_id', $actorId);
                });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    // A bell notice to every Project Approver covering the project; never to the person who asked.
    public function notifyManagers(Employee $actor, Request $request, object $project, string $name, bool $isResubmitted): void
    {
        $who = PortalActivityCopy::actorName($actor, $request, true);
        $projectId = (int) $project->id;
        foreach (PortalRules::holders([self::RULE]) as $holder) {
            if (! self::reachCovers($holder['reaches'][self::RULE] ?? null, $holder['employee'], $project)) {
                continue;
            }
            $this->audit->notifyIfOther(
                $holder['employee'],
                $actor,
                PortalNotificationType::PROJECT_REQUESTED,
                $isResubmitted ? 'Project sent again' : 'New project to approve',
                PortalActivityCopy::notifyProjectRequested($who, $name, $isResubmitted),
                PortalShellPath::MANAGE_PROJECTS,
                'Open Manage / Projects',
                ['recordId' => (string) $projectId],
            );
        }
    }

    // A closed project waits for its department heads (Edit closed projects over it) to approve the close.
    public function notifyDepartmentHeads(Employee $actor, Request $request, object $project, string $name): void
    {
        $who = PortalActivityCopy::actorName($actor, $request, true);
        foreach (PortalRules::holders([PortalProjectPowers::EDIT_CLOSED]) as $holder) {
            if (! PortalProjectPowers::coversProject($holder['reaches'][PortalProjectPowers::EDIT_CLOSED] ?? null, $holder['employee'], $project)) {
                continue;
            }
            $this->audit->notifyIfOther(
                $holder['employee'],
                $actor,
                PortalNotificationType::PROJECT_REQUESTED,
                'Project closed',
                PortalActivityCopy::notifyProjectClosed($who, $name),
                PortalShellPath::MANAGE_PROJECTS,
                'Open Manage / Projects',
                ['recordId' => (string) $project->id],
            );
        }
    }

    // The project powers' reading, with own also counting the projects the approver added.
    private static function reachCovers(?string $reach, Employee $approver, object $project): bool
    {
        if ($reach === 'own' && (int) ($project->requested_by ?? 0) === (int) $approver->getKey()) {
            return true;
        }

        return PortalProjectPowers::coversProject($reach, $approver, $project);
    }
}
