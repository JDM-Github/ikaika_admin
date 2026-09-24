<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Access: the fine-grained rules a person effectively holds (PortalAccessGraph) and the ones they
 * switched off for themselves (access_toggles). Display only for now: no page reads these rules to
 * decide access yet, so switching one off changes nothing but this list.
 */
final class PortalAccess
{
    public function __construct(private readonly PortalAudit $audit) {}

    /**
     * @return array{section: string, resource: string, tier: string, data: list<array<string, mixed>>}
     */
    public function powers(Employee $actor): array
    {
        $graph = PortalAccessGraph::load();
        $held = $graph->effective((int) $actor->getKey());
        $catalog = $graph->catalog();
        $off = $this->switchedOff($actor);

        $data = [];
        foreach ($held as $key => $rule) {
            $row = $catalog[$key];
            $data[] = [
                'key' => $key,
                'parentKey' => $row->parent_key,
                'label' => $row->label,
                'description' => $row->description,
                'supportsReach' => (bool) $row->supports_reach,
                'reach' => $rule['reach'],
                'includeExecutives' => $rule['executives'],
                'canToggle' => (bool) $row->can_toggle,
                'isSwitchedOn' => ! isset($off[$key]),
                'isActive' => $this->isActive($catalog, $off, $key),
                'fromRole' => $rule['fromRole'],
                'givenBy' => $graph->names($rule['givenBy']),
            ];
        }

        return [
            'section' => 'access',
            'resource' => 'powers',
            'tier' => PortalAccessGraph::tier($actor),
            'data' => $data,
        ];
    }

    /**
     * Switches one of the actor's own rules off or on. Only a rule they hold, and never one the
     * catalog marks as always on; repeating the current state changes and logs nothing.
     *
     * @return array{section: string, resource: string, tier: string, data: list<array<string, mixed>>}
     */
    public function setSwitch(Employee $actor, string $key, bool $on, Request $request): array
    {
        $graph = PortalAccessGraph::load();
        if (! isset($graph->effective((int) $actor->getKey())[$key])) {
            abort(404, 'You do not hold that power.');
        }
        if (! (bool) $graph->catalog()[$key]->can_toggle) {
            abort(422, 'That power always stays on.');
        }

        $toggles = DB::connection('portal')->table('access_toggles');
        $changed = $on
            ? $toggles->where('employee_id', $actor->getKey())->where('permission_key', $key)->delete() > 0
            : $toggles->insertOrIgnore(['employee_id' => $actor->getKey(), 'permission_key' => $key]) > 0;

        if ($changed) {
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                'access.switches',
                '{UserName|You} switched '.($on ? 'on' : 'off').' '.$graph->path($key),
                $key,
                $request,
                ['key' => $key, 'on' => $on],
            );
        }

        return $this->powers($actor);
    }

    /**
     * @return array<string, true>
     */
    private function switchedOff(Employee $actor): array
    {
        return array_fill_keys(
            DB::connection('portal')->table('access_toggles')->where('employee_id', $actor->getKey())->pluck('permission_key')->all(),
            true,
        );
    }

    /**
     * On only while its own switch and every ancestor's switch are on.
     *
     * @param  array<string, object>  $catalog
     * @param  array<string, true>  $off
     */
    private function isActive(array $catalog, array $off, string $key): bool
    {
        for ($current = $key; $current !== null; $current = $catalog[$current]->parent_key ?? null) {
            if (isset($off[$current])) {
                return false;
            }
        }

        return true;
    }
}
