<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rules a person may use right now: what they effectively hold (PortalAccessGraph, every rule
 * in Developer mode) minus what they switched off for themselves on the Access page. A power is off
 * while its own switch or any page above it is off. This is what enforcement asks, and what the
 * session tells the portal so it can hide what cannot be opened.
 */
final class PortalRules
{
    private const ATTRIBUTE = 'portalActiveRules';

    /**
     * Active rule => reach, in catalog order. Worked out once per request.
     *
     * @return array<string, ?string>
     */
    public static function active(Employee $employee, Request $request): array
    {
        $cacheKey = $employee->getKey().':'.(PortalDeveloperMode::isOn($employee, $request) ? 'dev' : 'default');
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if (is_array($cached) && ($cached['key'] ?? null) === $cacheKey) {
            return $cached['rules'];
        }

        $graph = PortalAccessGraph::forViewer($employee, $request);
        $catalog = $graph->catalog();
        $off = self::switchedOff($employee);
        $rules = [];
        foreach ($graph->effective((int) $employee->getKey()) as $key => $rule) {
            if (self::isSwitchedOn($catalog, $off, $key)) {
                $rules[$key] = $rule['reach'];
            }
        }
        $request->attributes->set(self::ATTRIBUTE, ['key' => $cacheKey, 'rules' => $rules]);

        return $rules;
    }

    public static function holds(Employee $employee, Request $request, string $key): bool
    {
        return array_key_exists($key, self::active($employee, $request));
    }

    /**
     * The rules the person switched off for themselves.
     *
     * @return array<string, true>
     */
    public static function switchedOff(Employee $employee): array
    {
        return array_fill_keys(
            DB::connection('portal')->table('access_toggles')->where('employee_id', $employee->getKey())->pluck('permission_key')->all(),
            true,
        );
    }

    /**
     * On only while its own switch and every ancestor's switch are on.
     *
     * @param  array<string, object>  $catalog
     * @param  array<string, true>  $off
     */
    public static function isSwitchedOn(array $catalog, array $off, string $key): bool
    {
        for ($current = $key; $current !== null; $current = $catalog[$current]->parent_key ?? null) {
            if (isset($off[$current])) {
                return false;
            }
        }

        return true;
    }
}
