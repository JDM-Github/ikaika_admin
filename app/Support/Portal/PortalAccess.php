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
    public function powers(Employee $actor, Request $request): array
    {
        $graph = PortalAccessGraph::forViewer($actor, $request);
        $held = $graph->effective((int) $actor->getKey());
        $catalog = $graph->catalog();
        $off = PortalRules::switchedOff($actor);
        $narrowed = PortalRules::selfReach($actor);

        $data = [];
        foreach ($held as $key => $rule) {
            $row = $catalog[$key];
            $data[] = [
                'key' => $key,
                'parentKey' => $row->parent_key,
                'label' => $row->label,
                'description' => $row->description,
                'supportsReach' => (bool) $row->supports_reach,
                'reach' => PortalRules::narrower($rule['reach'], $narrowed[$key] ?? null),
                'heldReach' => $rule['reach'],
                'includeExecutives' => $rule['executives'],
                'canToggle' => (bool) $row->can_toggle,
                'isSwitchedOn' => ! isset($off[$key]),
                'isActive' => PortalRules::isSwitchedOn($catalog, $off, $key),
                'fromRole' => $rule['fromRole'],
                'givenBy' => $graph->names($rule['givenBy']),
            ];
        }

        return [
            'section' => 'access',
            'resource' => 'powers',
            'tier' => $graph->actingTier((int) $actor->getKey()),
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
        $graph = PortalAccessGraph::forViewer($actor, $request);
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

        return $this->powers($actor, $request);
    }

    /**
     * Narrows one of the actor's own reach rules, or widens it back up to what they hold. The
     * reach they hold means no narrowing, so that row goes; repeating the current reach logs nothing.
     *
     * @return array{section: string, resource: string, tier: string, data: list<array<string, mixed>>}
     */
    public function setReach(Employee $actor, string $key, string $reach, Request $request): array
    {
        $held = PortalAccessGraph::forViewer($actor, $request)->effective((int) $actor->getKey())[$key] ?? null;
        if ($held === null) {
            abort(404, 'You do not hold that power.');
        }
        if ($held['reach'] === null) {
            abort(422, 'That power has no reach to choose.');
        }
        if (PortalAccessGraph::reachRank($reach) === 0 || PortalAccessGraph::reachRank($reach) > PortalAccessGraph::reachRank($held['reach'])) {
            abort(422, 'You cannot reach further than you hold that power.');
        }

        $rows = DB::connection('portal')->table('access_self_reach');
        $before = PortalRules::selfReach($actor)[$key] ?? $held['reach'];
        if ($reach === $held['reach']) {
            $rows->where('employee_id', $actor->getKey())->where('permission_key', $key)->delete();
        } else {
            $rows->updateOrInsert(['employee_id' => $actor->getKey(), 'permission_key' => $key], ['reach' => $reach]);
        }

        if ($before !== $reach) {
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                'access.reach',
                '{UserName|You} set '.PortalAccessGraph::load()->path($key).' to reach '.$reach,
                $key,
                $request,
                ['key' => $key, 'reach' => $reach],
            );
        }

        return $this->powers($actor, $request);
    }
}
