<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\DB;

/**
 * Everyone but the first who works on a report (2026-10-22, user: an issue can have more than one assignee). The
 * first is bug_reports.assignee_id, which Jira's single assignee is and older apps read; the others, in the order
 * they were chosen, are the issue's watchers in Jira. Jira's assignee coming back replaces the first alone, and
 * someone it makes the first is one of the others no longer.
 */
final class PortalBugReportAssignees
{
    public const MAX = 10;

    /**
     * The others on each report, in order.
     *
     * @param  iterable<int|string>  $reportIds
     * @return array<int, list<int>>
     */
    public static function othersOf(iterable $reportIds): array
    {
        $ids = array_values(array_unique(array_map('intval', [...$reportIds])));
        if ($ids === []) {
            return [];
        }
        $others = [];
        foreach (DB::connection('portal')->table('bug_report_other_assignees')->whereIn('bug_report_id', $ids)->orderBy('position')->get() as $row) {
            $others[(int) $row->bug_report_id][] = (int) $row->employee_id;
        }

        return $others;
    }

    /**
     * @param  list<int>  $others
     */
    public static function setOthers(int $reportId, array $others): void
    {
        DB::connection('portal')->table('bug_report_other_assignees')->where('bug_report_id', $reportId)->delete();
        if ($others !== []) {
            DB::connection('portal')->table('bug_report_other_assignees')->insert(array_map(
                static fn (int $employeeId, int $position): array => ['bug_report_id' => $reportId, 'employee_id' => $employeeId, 'position' => $position],
                $others,
                array_keys($others),
            ));
        }
    }

    // The first now, who is one of the others no longer.
    public static function madeFirst(int $reportId, int $employeeId): void
    {
        DB::connection('portal')->table('bug_report_other_assignees')->where('bug_report_id', $reportId)->where('employee_id', $employeeId)->delete();
    }
}
