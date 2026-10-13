<?php

namespace App\Support\Portal;

use Illuminate\Database\Eloquent\Model;

/**
 * The activity code catalogue, for allowed activities and the project form. Activity codes are never a
 * project's scopes: only Engineering has scopes (the WBS tree), every other department has none yet.
 */
final class PortalActivityScopes
{
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
