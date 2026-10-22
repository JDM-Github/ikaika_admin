<?php

namespace App\Support\Portal;

use Illuminate\Support\Facades\DB;

/**
 * Where a report sits (2026-10-22, user: epics are reports too, and a report can sit under another), as Jira's
 * levels go: an epic on top, an issue under an epic or under nothing, and a sub-task under an issue. A sub-task has
 * none of its own and an epic sits under nothing, so no chain or loop forms. A parent in the Recycle Bin counts as
 * none, and leaves its children where they are.
 */
final class PortalBugReportHierarchy
{
    public const EPIC = 1;

    public const ISSUE = 0;

    public const SUBTASK = -1;

    public static function level(string $kind, ?object $parent): int
    {
        if ($kind === PortalBugReports::KIND_EPIC) {
            return self::EPIC;
        }

        return $parent !== null && (string) $parent->kind !== PortalBugReports::KIND_EPIC ? self::SUBTASK : self::ISSUE;
    }

    // A report on the page or archived, as a parent is read: never one in the Recycle Bin.
    public static function live(mixed $id): ?object
    {
        return $id === null ? null : DB::connection('portal')->table('bug_reports')->where('id', (int) $id)->whereNull('deleted_at')->first();
    }

    // Whether a report is a sub-task: under one that is no epic.
    public static function isSubtask(object $row): bool
    {
        return self::level((string) $row->kind, self::live($row->parent_report_id ?? null)) === self::SUBTASK;
    }

    public static function hasChildren(int $id): bool
    {
        return DB::connection('portal')->table('bug_reports')->where('parent_report_id', $id)->whereNull('deleted_at')->exists();
    }

    /**
     * The reports under each, oldest first, as the page lists them.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{id: string, title: string, kind: string, status: string}>>
     */
    public static function childrenOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $children = [];
        $rows = DB::connection('portal')->table('bug_reports')->whereIn('parent_report_id', $ids)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'parent_report_id', 'title', 'kind', 'status']);
        foreach ($rows as $row) {
            $children[(int) $row->parent_report_id][] = ['id' => (string) $row->id, 'title' => (string) $row->title, 'kind' => (string) $row->kind, 'status' => (string) $row->status];
        }

        return $children;
    }
}
