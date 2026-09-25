<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rules a person may use right now: what they effectively hold (PortalAccessGraph, every rule
 * in Developer mode) minus what they switched off for themselves on the Access page, at no more
 * than the reach they narrowed themselves to. A power is off while its own switch or any page above
 * it is off. This is what enforcement asks, and what the session tells the portal so it can hide
 * what cannot be opened.
 */
final class PortalRules
{
    public const REFUSAL = 'You do not have access to this page.';

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
        $narrowed = self::selfReach($employee);
        $rules = [];
        foreach ($graph->effective((int) $employee->getKey()) as $key => $rule) {
            if (self::isSwitchedOn($catalog, $off, $key)) {
                $rules[$key] = self::narrower($rule['reach'], $narrowed[$key] ?? null);
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
     * Refuses unless they hold at least one of the rules: a plain 403, not a security alert, since
     * switching a power off yourself lands here too.
     */
    public static function require(Employee $employee, Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            if (self::holds($employee, $request, $key)) {
                return;
            }
        }

        abort(403, self::REFUSAL);
    }

    /**
     * How far the person narrowed each reach rule for themselves.
     *
     * @return array<string, string>
     */
    public static function selfReach(Employee $employee): array
    {
        return DB::connection('portal')->table('access_self_reach')->where('employee_id', $employee->getKey())->pluck('reach', 'permission_key')->all();
    }

    // Their own narrowing only ever narrows: a wider or missing choice leaves what they hold.
    public static function narrower(?string $held, ?string $chosen): ?string
    {
        if ($chosen === null || $held === null) {
            return $held;
        }

        return PortalAccessGraph::reachRank($chosen) < PortalAccessGraph::reachRank($held) ? $chosen : $held;
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
