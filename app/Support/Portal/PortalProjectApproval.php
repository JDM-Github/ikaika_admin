<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;

/**
 * Who may approve a project, and telling them one waits (Manage / Projects, 2026-10-01).
 *
 * Project Manager (projects.manager) covers projects by the project's department: all covers every
 * department, department covers the manager's own, own covers none. A project added by someone it
 * covers is approved at once; any other waits as Pending for a manager who covers it.
 */
final class PortalProjectApproval
{
    public const RULE = 'projects.manager';

    public const APPROVED = 'Approved';

    public const PENDING = 'Pending';

    public const REJECTED = 'Rejected';

    public function __construct(private readonly PortalAudit $audit) {}

    public function covers(Employee $actor, Request $request, mixed $department): bool
    {
        return self::reachCovers(PortalRules::reach($actor, $request, self::RULE), $actor, $department);
    }

    // A bell notice to every Project Manager covering the department; never to the person who asked.
    public function notifyManagers(Employee $actor, Request $request, int $projectId, string $name, mixed $department, bool $isResubmitted): void
    {
        $who = PortalActivityCopy::actorName($actor, $request, true);
        foreach (PortalRules::holders([self::RULE]) as $holder) {
            if (! self::reachCovers($holder['reaches'][self::RULE] ?? null, $holder['employee'], $department)) {
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

    private static function reachCovers(?string $reach, Employee $manager, mixed $department): bool
    {
        if ($reach === 'all') {
            return true;
        }
        if ($reach !== 'department') {
            return false;
        }
        $own = PortalPreview::department($manager->department);

        return $own !== '' && $own === PortalPreview::department($department);
    }
}
