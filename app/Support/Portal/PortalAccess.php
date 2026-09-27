<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Access: the fine-grained rules a person effectively holds (PortalAccessGraph) and the ones they
 * switched off or narrowed for themselves (access_toggles, access_self_reach). Neither is logged:
 * it is their own choice about powers they already hold, and it can never gain them a new one.
 */
final class PortalAccess
{
    /**
     * @return array{section: string, resource: string, tier: string, data: list<array<string, mixed>>}
     */
    public function powers(Employee $actor, Request $request): array
    {
        $graph = PortalAccessGraph::forViewer($actor, $request);
        $held = $graph->effective((int) $actor->getKey());
        $catalog = $graph->catalog();
        $viewerIsDeveloper = $graph->actingTier((int) $actor->getKey()) === PortalAccessGraph::TIER_DEVELOPER;
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
                'fromDefault' => $rule['personal'] ?? false,
                'givenBy' => $graph->makerNames(PortalAccessGraph::givers($rule), $viewerIsDeveloper),
                'lists' => $graph->supportsList($key) ? $this->lists($graph, (int) $actor->getKey(), $key, $viewerIsDeveloper) : [],
                'ignoresLists' => $graph->supportsList($key) && PortalRules::holds($actor, $request, $key.'.'.PortalRules::UNLISTED),
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
     * Who narrowed the actor's rule with a white or black list, so the Access page can say why a
     * person is missing.
     *
     * @return list<array{name: string, mode: string}>
     */
    private function lists(PortalAccessGraph $graph, int $actorId, string $key, bool $viewerIsDeveloper): array
    {
        $lists = [];
        foreach ($graph->listsOn($actorId, $key) as $list) {
            $lists[] = ['name' => $graph->makerNames([['id' => $list['maker'], 'asDeveloper' => $list['asDeveloper']]], $viewerIsDeveloper)[0] ?? '', 'mode' => $list['mode']];
        }

        return $lists;
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
        if ($on) {
            $toggles->where('employee_id', $actor->getKey())->where('permission_key', $key)->delete();
        } else {
            $toggles->insertOrIgnore(['employee_id' => $actor->getKey(), 'permission_key' => $key]);
        }

        return $this->powers($actor, $request);
    }

    /**
     * Narrows one of the actor's own reach rules, or widens it back up to what they hold. The
     * reach they hold means no narrowing, so that row goes.
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
        if ($reach === $held['reach']) {
            $rows->where('employee_id', $actor->getKey())->where('permission_key', $key)->delete();
        } else {
            $rows->updateOrInsert(['employee_id' => $actor->getKey(), 'permission_key' => $key], ['reach' => $reach]);
        }

        return $this->powers($actor, $request);
    }
}
