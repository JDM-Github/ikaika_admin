<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;

/**
 * The view a developer is previewing in the portal, sent on every request. The header can only
 * take access away: a developer previewing Admin or Member is treated as not being a developer,
 * and nobody gains anything by sending it.
 */
final class PortalPreview
{
    public const HEADER = 'X-Portal-View';

    public static function actsAsDeveloper(Employee $employee, Request $request): bool
    {
        if (! (bool) $employee->is_developer) {
            return false;
        }

        $view = strtolower(trim((string) $request->header(self::HEADER)));

        return $view !== 'admin' && $view !== 'member';
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
