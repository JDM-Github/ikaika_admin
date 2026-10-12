<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreDeveloperActs;

/**
 * The portal's answer for the ledger, by the same rule as its logs: the signed-in developer in
 * Developer mode, unless it is their own work within Default. Reads the request at call time,
 * since the ledger outlives any one request.
 */
final class PortalCoreDeveloperActs implements CoreDeveloperActs
{
    public function isDevelopersAct(int $actorId): bool
    {
        $request = request();
        $employee = $request->attributes->get('portalEmployee');

        return $employee instanceof Employee
            && (int) $employee->getKey() === $actorId
            && PortalDeveloperMode::isOn($employee, $request)
            && PortalRules::isDevelopersAct($request);
    }
}
