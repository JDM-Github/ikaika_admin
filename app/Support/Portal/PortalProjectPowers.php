<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Edit projects, Manage scopes and Delete projects: reach rules under View Projects. All is every
 * project, department the actor's department's plus the ones they lead, own the ones they are on,
 * lead only the ones they lead. Those three reaches are given, not a member's own, so a lead's own
 * project is theirs until a manager limits it or they switch it off. Whoever asked for a project may
 * still edit it while it is not approved. Whoever View Projects reaches may change the project's members
 * and support members, and nothing else. A Completed or Closed project is locked for all of them; only
 * Edit closed projects (the department heads) still edits it.
 */
final class PortalProjectPowers
{
    public const EDIT = 'projects.edit';

    public const SCOPES = 'projects.scopes';

    public const DELETE = 'projects.delete';

    public const KEYS = [self::EDIT, self::SCOPES, self::DELETE];

    public const CREATE = 'projects.create';

    public const DIRECT = 'projects.create.direct';

    public const LEAD = 'projects.lead';

    public const VIEW = 'projects.view';

    // Department heads only (2026-10-12, supervisor): Nathaniel and Steven by personal default.
    public const EDIT_CLOSED = 'projects.edit.closed';

    // Not a form power: Manage / References reads the same project options the form does, so its
    // own rule must open that read too.
    public const REFERENCES = 'projects.references';

    // Only these reach a project row, so only they offer the "the projects you lead" reach.
    public static function supportsLeadReach(string $key): bool
    {
        return in_array($key, [...self::KEYS, PortalProjectApproval::RULE], true);
    }

    // Whether the rule, at the reach the actor holds it now, covers this project row.
    public static function allows(Employee $actor, Request $request, string $key, object $project): bool
    {
        if ($key !== self::DELETE && self::isLocked($actor, $request, $project)) {
            return false;
        }

        return self::coversProject(PortalRules::reach($actor, $request, $key), $actor, $project)
            || ($key !== self::DELETE && self::isRequester($actor, $project));
    }

    /**
     * The three answers for the project board, from reaches read once per request.
     *
     * @param  array<string, ?string>  $reaches
     * @return array{canEdit: bool, canEditMembers: bool, canManageScopes: bool, canDelete: bool}
     */
    public static function flags(array $reaches, Employee $actor, object $project): array
    {
        $isRequest = self::isRequester($actor, $project);
        $isOpen = ! self::isFinished($project) || self::coversProject($reaches[self::EDIT_CLOSED] ?? null, $actor, $project);
        $canEdit = $isOpen && (self::coversProject($reaches[self::EDIT] ?? null, $actor, $project) || $isRequest);

        return [
            'canEdit' => $canEdit,
            'canEditMembers' => $canEdit || ($isOpen && self::coversProject($reaches[self::VIEW] ?? null, $actor, $project)),
            'canManageScopes' => $isOpen && (self::coversProject($reaches[self::SCOPES] ?? null, $actor, $project) || $isRequest),
            'canDelete' => self::coversProject($reaches[self::DELETE] ?? null, $actor, $project),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    public static function reaches(Employee $actor, Request $request): array
    {
        $reaches = [];
        foreach (self::KEYS as $key) {
            $reaches[$key] = PortalRules::reach($actor, $request, $key);
        }
        $reaches[self::VIEW] = PortalRules::reach($actor, $request, self::VIEW);
        $reaches[self::EDIT_CLOSED] = PortalRules::reach($actor, $request, self::EDIT_CLOSED);

        return $reaches;
    }

    // Members and support members (2026-10-12, user): View Projects' reach is enough, and no approval follows.
    public static function reachesPeople(Employee $actor, Request $request, object $project): bool
    {
        return ! self::isLocked($actor, $request, $project)
            && self::coversProject(PortalRules::reach($actor, $request, self::VIEW), $actor, $project);
    }

    // Whether the edit form opens at all for this person: the whole project, or its people.
    public static function opensEditor(Employee $actor, Request $request, object $project): bool
    {
        return self::allows($actor, $request, self::EDIT, $project) || self::reachesPeople($actor, $request, $project);
    }

    // Completed or Closed, whatever its approval (2026-10-12, supervisor), and outside the actor's Edit closed projects.
    public static function isLocked(Employee $actor, Request $request, object $project): bool
    {
        return self::isFinished($project)
            && ! self::coversProject(PortalRules::reach($actor, $request, self::EDIT_CLOSED), $actor, $project);
    }

    private static function isFinished(object $project): bool
    {
        return in_array(PortalViewProjectPresenter::status($project->status ?? null), ['completed', 'closed'], true);
    }

    // Add projects and Project Lead reach departments: all is any, department (or own) only the person's own.
    public static function reachesDepartment(?string $reach, mixed $ownDepartment, mixed $department): bool
    {
        if ($reach === null) {
            return false;
        }
        if ($reach === 'all') {
            return true;
        }
        $own = PortalPreview::department($ownDepartment);

        return $own !== '' && $own === PortalPreview::department($department);
    }

    /**
     * Every active person's Project Lead reach right now, by employee id; whoever does not hold it is absent.
     *
     * @return array<int, string>
     */
    public static function leadReaches(): array
    {
        $reaches = [];
        foreach (PortalRules::holders([self::LEAD]) as $holder) {
            $reach = $holder['reaches'][self::LEAD] ?? null;
            if ($reach !== null) {
                $reaches[(int) $holder['employee']->getKey()] = $reach;
            }
        }

        return $reaches;
    }

    // A project reach over one row: all, department (plus the ones led), own (on it), lead.
    public static function coversProject(?string $reach, Employee $actor, object $project): bool
    {
        if ($reach === null) {
            return false;
        }
        if ($reach === 'all') {
            return true;
        }
        $leads = self::leads($actor, $project->project_lead_email ?? null);
        if ($reach === 'lead') {
            return $leads;
        }
        $own = PortalPreview::department($actor->department);
        if ($reach === 'department') {
            return ($own !== '' && $own === PortalPreview::department($project->department ?? null)) || $leads;
        }
        if ($reach === 'own') {
            return self::isOn($actor, $project);
        }

        return false;
    }

    // On the project: a row on employees_projects. The board carries the flag; a single row reads it.
    private static function isOn(Employee $actor, object $project): bool
    {
        if (isset($project->is_member)) {
            return (bool) $project->is_member;
        }
        $projectId = (int) ($project->id ?? 0);
        if ($projectId === 0) {
            return false;
        }

        return DB::connection('portal')->table('employees_projects')
            ->where('project_id', $projectId)
            ->where('employee_id', $actor->getKey())
            ->exists();
    }

    // The lead is matched by email, never by name.
    private static function leads(Employee $actor, mixed $leadEmail): bool
    {
        $lead = is_string($leadEmail) ? strtolower(trim($leadEmail)) : '';
        $own = is_string($actor->email) ? strtolower(trim($actor->email)) : '';

        return $lead !== '' && $lead === $own;
    }

    // The creator edits and scopes their own project at any status (2026-10-12, user); once it is approved,
    // their edit sends it back for approval (PortalProjectEditor::sendsBackForApproval). Never delete.
    private static function isRequester(Employee $actor, object $project): bool
    {
        return (int) ($project->requested_by ?? 0) === (int) $actor->getKey();
    }
}
