<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes a project's Trail history: one project_action_history row, linked through
 * projects_action_history, the same two tables Airtable's rows already fill. Action names follow
 * Airtable's own ("Project Creation", "Project Edited") so old and new rows read alike.
 *
 * Each row is ledgered as it is written, so the Airtable sync adds it to Project Action History, linked
 * to its project (2026-10-14, user). The actor is named as other people read them, so a developer's
 * act in Developer mode reads "A developer".
 */
final class PortalProjectTrail
{
    public const CREATED = 'Project Creation';

    public const EDITED = 'Project Edited';

    public const SCOPES = 'Scopes Changed';

    public const DELETED = 'Project Deleted';

    public const RESTORED = 'Project Restored';

    public const APPROVED = 'Project Creation Approved';

    public const REJECTED = 'Project Creation Rejected';

    public const RESUBMITTED = 'Project Creation Resubmission';

    public const REOPENED = 'Project Creation Reopened';

    // Closing follows Airtable's own names too; 'Project Closed' is only read, from rows written before 2026-10-14.
    public const CLOSED = 'Project Closed';

    public const CLOSING_SUBMITTED = 'Project Closing Submission';

    public const CLOSING_APPROVED = 'Project Closing Approved';

    public const CLOSING_REJECTED = 'Project Closing Rejected';

    public const CLOSING_RESUBMITTED = 'Project Closing Resubmission';

    public const CLOSING_REOPENED = 'Project Closing Reopened';

    /**
     * Each stored action name => what Trail history calls it and the kind its chip shows (2026-10-14, user).
     * Airtable's older names are here too, so its rows read the same way.
     */
    private const DESCRIPTIONS = [
        'project creation' => ['Project created', 'created'],
        'project creation approved' => ['Project approved', 'approved'],
        'project creation rejected' => ['Project rejected', 'rejected'],
        'project creation resubmission' => ['Sent back for approval', 'pending'],
        'project creation reopened' => ['Approval reopened, waiting again', 'pending'],
        'project creation edited' => ['Project edited', 'edited'],
        'project edited' => ['Project edited', 'edited'],
        'scopes changed' => ['Scopes changed', 'scopes'],
        'project deleted' => ['Moved to the Recycle Bin', 'deleted'],
        'project restored' => ['Restored from the Recycle Bin', 'restored'],
        'project closed' => ['Closing requested', 'pending'],
        'project closing submission' => ['Closing requested', 'pending'],
        'project closing approved' => ['Closing approved, project closed', 'closed'],
        'project closing rejected' => ['Closing rejected', 'rejected'],
        'project closing resubmission' => ['Closing sent back for approval', 'pending'],
        'project closing reopened' => ['Closing approval reopened, waiting again', 'pending'],
        'checklist submitted' => ['Completion checklist submitted', 'checklist'],
    ];

    /**
     * The title and chip kind for a stored action name; an unknown one keeps its own words and no kind.
     *
     * @return array{title: string, kind: ?string}
     */
    public static function describe(string $action): array
    {
        $known = self::DESCRIPTIONS[strtolower(trim($action))] ?? null;

        return $known === null
            ? ['title' => trim($action) !== '' ? trim($action) : 'Project updated', 'kind' => null]
            : ['title' => $known[0], 'kind' => $known[1]];
    }

    public function __construct(private readonly CoreLedger $ledger) {}

    public function record(int $projectId, string $actionName, Employee $actor, ?Request $request, ?string $remarks = null): void
    {
        // The row and its ledger entry commit together, so no history row is written without one.
        $this->ledger->within($this->connection(), function () use ($projectId, $actionName, $actor, $request, $remarks): void {
            $actionId = (int) $this->connection()->table('project_action_history')->insertGetId([
                'action_name' => $actionName,
                'created_by' => PortalActivityCopy::actorName($actor, $request, true),
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
            $this->connection()->table('projects_action_history')->insert([
                'project_id' => $projectId,
                'project_action_id' => $actionId,
            ]);
            // Airtable keeps the same history (2026-10-14, user).
            $this->ledger->recordAdd(
                CoreLedger::PRODUCT_PORTAL,
                'project_action_history:'.$actionId,
                CoreLedger::PRODUCT_PORTAL.'.project_action_history',
                ['id' => (string) $actionId, 'projectId' => (string) $projectId, 'actionName' => $actionName],
                'projects.trail',
                (string) $actionId,
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
        });
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
