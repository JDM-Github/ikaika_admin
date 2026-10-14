<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The departments people work in and their heads (2026-10-14, user): company_departments, one head
 * each, MIS's Nathaniel and Engineering's Steven. Admin and General are project buckets, not
 * departments, so they live only in the project list (the departments table). A head is whoever
 * this table names, never someone's role level.
 */
final class PortalDepartments
{
    /**
     * The departments by name, A to Z.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::rows()->pluck('name')->map(static fn (mixed $name): string => trim((string) $name))->filter()->values()->all();
    }

    // The active head of a department, or null when it has none or is not a department.
    public static function headOf(mixed $department): ?Employee
    {
        $key = PortalPreview::department($department);
        $row = self::rows()->first(static fn (object $row): bool => PortalPreview::department($row->name) === $key);
        if ($key === '' || $row === null || $row->head_employee_id === null) {
            return null;
        }
        $head = Employee::query()->find((int) $row->head_employee_id);

        return $head instanceof Employee && PortalPreview::department($head->status) === 'active' ? $head : null;
    }

    // The department this person heads, or null.
    public static function headedBy(Employee $employee): ?string
    {
        $name = DB::connection('portal')->table('company_departments')->where('head_employee_id', $employee->getKey())->value('name');

        return $name === null ? null : trim((string) $name);
    }

    // ponytail: read on each call; the table holds a handful of rows, memoise if it ever grows.
    private static function rows(): Collection
    {
        return DB::connection('portal')->table('company_departments')->orderBy('name')->get(['name', 'head_employee_id']);
    }
}
