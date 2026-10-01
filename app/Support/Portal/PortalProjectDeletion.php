<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

/**
 * Deleting a project into the Recycle Bin, and putting it back.
 *
 * A stamped column rather than a removed row. Eight tables cascade from projects(id) --
 * employees_projects, projects_clients, projects_project_scopes, projects_user_reports and four
 * more -- so a DELETE would take the memberships, the client links, the scope assignments and the
 * link to every submitted report with it, and nothing held in the recycle payload could put those
 * back. Stamping deleted_at leaves all of it standing and makes restore exact.
 *
 * The recycle row is written first and reverted if the stamp fails, which is the order
 * PortalSubmittedReports uses: a bin entry pointing at a project that is still live is recoverable,
 * a deleted project with no bin entry is not.
 */
final class PortalProjectDeletion
{
    private const CORE_TARGET = 'portal.projects';

    private const CORE_RESOURCE = 'projects';

    private const DELETE_RULE = 'projects.view.delete';

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalRecycleBin $recycleBin,
        private readonly PortalViewProjects $projects,
        private readonly PortalAudit $audit,
    ) {}

    public function destroy(Employee $actor, int $projectId, Request $request): void
    {
        $row = $this->connection()
            ->table('projects')
            ->where('id', $projectId)
            ->whereNull('deleted_at')
            ->first([
                'id',
                'project_number',
                'project_name',
                'department',
                'status',
                'project_lead_email',
            ]);

        if ($row === null) {
            abort(404, 'That project was not found.');
        }

        $this->assertMayDelete($actor, $row->project_lead_email ?? null, $request);

        $name = $this->text($row->project_name) ?? $this->text($row->project_number) ?? 'Project';
        $employeeId = (int) $actor->getKey();

        $written = $this->ledger->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL,
            CoreRecycleKey::project($projectId),
            self::CORE_TARGET,
            (string) $projectId,
            [
                // The keys Recycle Bin's own presenter reads back for the list row.
                'title' => $name,
                'kind' => 'project',
                'id' => (string) $projectId,
                'projectNumber' => $this->text($row->project_number),
                'projectName' => $this->text($row->project_name),
                'department' => $this->text($row->department),
                'status' => $this->text($row->status),
            ],
            self::CORE_RESOURCE,
            $employeeId,
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        try {
            $this->connection()
                ->table('projects')
                ->where('id', $projectId)
                ->update(['deleted_at' => now()]);
        } catch (Throwable $error) {
            $this->ledger->revert($written['recycle_id'], $written['action_id']);
            throw $error;
        }

        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            self::CORE_RESOURCE,
            PortalActivityCopy::deletedProject($name),
            (string) $projectId,
            $request,
            [
                'projectNumber' => $this->text($row->project_number),
                'projectName' => $this->text($row->project_name),
                'department' => $this->text($row->department),
            ],
        );

        $this->projects->bumpCache();
        $this->recycleBin->bumpCache();
    }

    /**
     * Put a recycled project back. The delete action stays in the ledger -- a restore is an add,
     * not an erasure of what happened.
     */
    public function restoreFromBin(Employee $actor, Recycle $row, Request $request): void
    {
        if ($row->product !== CoreLedger::PRODUCT_PORTAL) {
            abort(404, 'That item was not found.');
        }

        if (! self::owns($row)) {
            abort(422, 'This item cannot be restored from here.');
        }

        $projectId = (int) $row->record_id;
        $payload = is_array($row->payload) ? $row->payload : [];
        $name = $this->text($payload['title'] ?? null) ?? 'Project';

        $exists = $this->connection()->table('projects')->where('id', $projectId)->exists();
        if (! $exists) {
            abort(404, 'That project no longer exists.');
        }

        $this->connection()
            ->table('projects')
            ->where('id', $projectId)
            ->update(['deleted_at' => null]);

        // recordAdd, not revert: it drops the bin row and logs the restore as its own action, so the
        // original delete stays in the ledger. revert is for rolling back a delete that failed.
        $this->ledger->recordAdd(
            CoreLedger::PRODUCT_PORTAL,
            (string) $row->recycle_key,
            self::CORE_TARGET,
            ['projectId' => $projectId, 'projectName' => $name],
            self::CORE_RESOURCE,
            (string) $projectId,
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            self::CORE_RESOURCE,
            PortalActivityCopy::restoredProject($name),
            (string) $projectId,
            $request,
            ['projectName' => $name],
        );

        $this->projects->bumpCache();
        $this->recycleBin->bumpCache();
    }

    // Delete projects is a power (admins, executives and developers by preset, Developer mode always);
    // a project's lead may always delete their own, as the catalog row promises.
    private function assertMayDelete(Employee $actor, mixed $leadEmail, Request $request): void
    {
        if (PortalRules::holds($actor, $request, self::DELETE_RULE)) {
            return;
        }

        $lead = $this->text($leadEmail);
        $own = $this->text($actor->email ?? null);
        if ($lead !== null && $own !== null && strcasecmp($lead, $own) === 0) {
            return;
        }

        abort(403, 'Deleting a project needs the Delete projects power, unless you lead it.');
    }

    /** Whether this bin row is a project, so the Recycle Bin can route a restore to the right hand. */
    public static function owns(Recycle $row): bool
    {
        return $row->resource === self::CORE_RESOURCE
            || $row->database_target === self::CORE_TARGET;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
