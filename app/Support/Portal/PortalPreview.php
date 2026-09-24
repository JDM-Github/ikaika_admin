<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;

/**
 * Who acts as a developer on this request, and the department scope that follows from it. Only a
 * developer with Developer mode switched on (PortalDeveloperMode); in Default they are their role.
 */
final class PortalPreview
{
    public static function actsAsDeveloper(Employee $employee, Request $request): bool
    {
        return PortalDeveloperMode::isOn($employee, $request);
    }

    /**
     * The department whose people this admin may work with, or null for everyone. Only a developer
     * sees the whole company; executives work from their own portal, so they get no exception here.
     */
    public static function departmentScope(Employee $actor, Request $request): ?string
    {
        if (self::actsAsDeveloper($actor, $request)) {
            return null;
        }

        // An admin with no department recorded matches no one, rather than everyone.
        return self::department($actor->department);
    }

    public static function inScope(?string $scope, ?Employee $employee): bool
    {
        // Executives are managed from their own portal, so nobody works their rows here.
        if ($employee instanceof Employee && PortalRole::isExecutive($employee->role, $employee->role_level)) {
            return false;
        }

        return $scope === null
            || ($scope !== '' && $employee instanceof Employee && self::department($employee->department) === $scope);
    }

    public static function department(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
