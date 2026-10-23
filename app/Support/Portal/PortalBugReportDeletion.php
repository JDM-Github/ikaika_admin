<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use App\Support\Core\CoreRecycleKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deleting an issue or request into the deleter's Recycle Bin, and putting it back (2026-10-21, user).
 *
 * A stamped column rather than a removed row, as projects are deleted: the screenshots cascade from the row,
 * the issue's id is unique, and the report's number is in its Jira mark, the bell's notices and every
 * "Duplicate of #N" -- a DELETE would lose the first and a re-insert could break the rest. The bin's copy holds
 * only what its row shows: the title, the kind and status, the day, the page and the issue's key -- never the
 * description, the note, who sent it or where the screenshots are.
 *
 * An open linked report's issue moves to Cancelled first (PortalBugReports::cancelIssueBeforeRemoval), under the
 * report's lock. The recycle row is written next and reverted if the stamp fails, the order PortalProjectDeletion
 * keeps. Restoring sends nothing to Jira: the issue stays where it is, and the next read-back follows it again.
 */
final class PortalBugReportDeletion
{
    // The power to delete one, beside the page's rule (2026-10-21, user).
    public const RULE = 'bug-reports.delete';

    private const CORE_TARGET = 'portal.bug_reports';

    private const CORE_RESOURCE = 'bug-reports';

    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly PortalRecycleBin $recycleBin,
        private readonly PortalBugReports $reports,
        private readonly PortalBugReportsJira $jira,
        private readonly PortalAudit $audit,
    ) {}

    // Deleting one already deleted changes nothing, so a retried request answers as the first did.
    public function destroy(Employee $actor, int $id, Request $request): void
    {
        $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That issue or request was not found.');
        }
        if ($row->deleted_at !== null) {
            return;
        }

        $moved = null;
        $deleted = null;
        $held = $this->jira->withReportLock($id, function () use ($actor, $id, $request, &$moved, &$deleted): void {
            $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
            if ($row === null || $row->deleted_at !== null) {
                return;
            }
            $moved = $this->reports->cancelIssueBeforeRemoval($actor, $row, $request, 'Deleting');
            $row = DB::connection('portal')->table('bug_reports')->where('id', $id)->first() ?? $row;
            $written = $this->ledger->recycleDeleted(
                CoreLedger::PRODUCT_PORTAL,
                CoreRecycleKey::bugReport($id),
                self::CORE_TARGET,
                (string) $id,
                $this->binCopy($row, $moved),
                self::CORE_RESOURCE,
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );
            try {
                DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['deleted_at' => now('UTC')->format('Y-m-d H:i:s')]);
            } catch (Throwable $error) {
                $this->ledger->revert($written['recycle_id'], $written['action_id']);
                throw $error;
            }
            $deleted = $row;
        });
        if (! $held) {
            abort(409, PortalBugReports::BEING_SENT);
        }
        if ($deleted === null) {
            return;
        }

        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            self::CORE_RESOURCE,
            PortalActivityCopy::deletedBugReport((string) $deleted->title, $this->noun($deleted), $moved),
            (string) $id,
            $request,
            ['jiraCancelled' => $moved],
        );
        $this->recycleBin->bumpCache();
    }

    /**
     * Put a deleted one back on the page, archived still if it was. The delete stays in the ledger: a restore is
     * an add, not an erasure of what happened.
     */
    public function restoreFromBin(Employee $actor, Recycle $row, Request $request): void
    {
        if ($row->product !== CoreLedger::PRODUCT_PORTAL) {
            abort(404, 'That item was not found.');
        }
        if (! self::owns($row)) {
            abort(422, 'This item cannot be restored from here.');
        }

        $id = (int) $row->record_id;
        $report = DB::connection('portal')->table('bug_reports')->where('id', $id)->first();
        if ($report === null) {
            abort(404, 'That issue or request no longer exists.');
        }
        DB::connection('portal')->table('bug_reports')->where('id', $id)->update(['deleted_at' => null]);

        $this->ledger->recordAdd(
            CoreLedger::PRODUCT_PORTAL,
            (string) $row->recycle_key,
            self::CORE_TARGET,
            ['bugReportId' => $id, 'title' => (string) $report->title],
            self::CORE_RESOURCE,
            (string) $id,
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );
        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            self::CORE_RESOURCE,
            PortalActivityCopy::restoredBugReport((string) $report->title, $this->noun($report)),
            (string) $id,
            $request,
            ['title' => (string) $report->title],
        );
        $this->recycleBin->bumpCache();
    }

    // Whether this bin row is an issue or request, so the Recycle Bin can route a restore to the right hand.
    public static function owns(Recycle $row): bool
    {
        return $row->resource === self::CORE_RESOURCE
            || $row->database_target === self::CORE_TARGET;
    }

    /**
     * What the bin keeps: the keys its presenter reads for the list row, and the issue as its detail shows it.
     *
     * @return array<string, mixed>
     */
    private function binCopy(object $row, ?string $moved): array
    {
        $reportedOn = substr((string) $row->reported_on, 0, 10);

        return [
            'title' => (string) $row->title,
            'kind' => 'issue',
            'id' => (string) $row->id,
            'submittedOn' => $reportedOn,
            'issue' => [
                'kind' => array_key_exists((string) $row->kind, PortalBugReports::KINDS) ? (string) $row->kind : PortalBugReports::KIND_BUG,
                'status' => (string) $row->status,
                'reportedOn' => $reportedOn,
                'page' => $row->page,
                'jiraKey' => $row->jira_issue_key,
                'jiraCancelled' => $moved !== null,
                'images' => DB::connection('portal')->table('bug_report_images')->where('bug_report_id', $row->id)->count(),
            ],
        ];
    }

    private function noun(object $row): string
    {
        return (PortalBugReports::KINDS[(string) $row->kind] ?? PortalBugReports::KINDS[PortalBugReports::KIND_BUG])['noun'];
    }
}
