<?php

namespace App\Support\Portal;

use Illuminate\Database\Eloquent\Model;

/**
 * The activity code catalogue, for allowed activities and the project form. Activity codes are never a
 * project's scopes: those come from each department's scope tree (PortalScopeTree).
 */
final class PortalActivityScopes
{
    /**
     * Every activity code's id by the label a picker sends for it.
     *
     * @return array<string, int>
     */
    public static function idsByLabel(): array
    {
        $ids = [];
        foreach (Model::getConnectionResolver()->connection('portal')->table('activity_codes')->orderBy('id')->get(['id', 'id_no', 'name']) as $row) {
            $id = (int) $row->id;
            $name = trim((string) $row->name);
            $code = trim((string) $row->id_no);
            $labels = [PortalSubmittedReportPresenter::activityLabel($row->name, $row->id_no), $name, $code];
            if ($code !== '' && $name !== '') {
                $labels[] = $code.' - '.$name;
            }
            foreach ($labels as $label) {
                if ($label !== '') {
                    $ids[strtolower(trim($label))] ??= $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Every activity code, with the department it belongs to.
     *
     * @return list<array{code: string, name: string, department: string}>
     */
    public static function all(): array
    {
        $codes = [];
        foreach (Model::getConnectionResolver()->connection('portal')->table('activity_codes')->orderBy('id_no')->get(['id_no', 'name', 'department']) as $row) {
            $code = trim((string) $row->id_no);
            if ($code !== '') {
                $codes[] = ['code' => $code, 'name' => PortalProjectWork::activityName($row->name, $code), 'department' => trim((string) $row->department)];
            }
        }

        return $codes;
    }
}
