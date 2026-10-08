<?php

namespace App\Support\Portal;

use Illuminate\Database\Eloquent\Model;

/**
 * Scopes for a project that is not Engineering's (docs/power/view-projects-dashboard-plan.md, step 5):
 * Engineering picks from the WBS tree, every other department from the activity codes its people
 * report on -- its own department's and General's. They are stored in projects_wbs_scopes like a WBS
 * code, so the board and Manage Scopes read them the same way.
 */
final class PortalActivityScopes
{
    private const SHARED_DEPARTMENT = 'general';

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

    /**
     * The codes a project of this department may hold: none for Engineering (it uses the WBS tree),
     * otherwise its own department's and General's.
     *
     * @param  list<array{code: string, name: string, department: string}>  $all
     * @return list<array{code: string, name: string}>
     */
    public static function forDepartment(array $all, mixed $department): array
    {
        if (PortalProjectWork::isEngineering($department)) {
            return [];
        }
        $own = PortalPreview::department($department);
        $options = [];
        foreach ($all as $code) {
            $belongs = PortalPreview::department($code['department']);
            if ($belongs === self::SHARED_DEPARTMENT || ($own !== '' && $belongs === $own)) {
                $options[] = ['code' => $code['code'], 'name' => $code['name']];
            }
        }

        return $options;
    }
}
