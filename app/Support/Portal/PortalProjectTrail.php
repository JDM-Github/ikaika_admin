<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes a project's Trail history: one project_action_history row, linked through
 * projects_action_history, the same two tables Airtable's rows already fill. Action names follow
 * Airtable's own ("Project Creation", "Project Edited") so old and new rows read alike.
 *
 * Portal-only like the writes it records: no airtable_record_id, never synced. The actor is named
 * as other people read them, so a developer's act in Developer mode reads "A developer".
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

    public function record(int $projectId, string $actionName, Employee $actor, ?Request $request, ?string $remarks = null): void
    {
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
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
