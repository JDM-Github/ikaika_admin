<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manage / Users / Access Rules: one switch per power for someone the editor manages. Switching
 * on lifts the editor's own limits and gives the power if they still lack it; switching off takes
 * back the editor's gift and limits whatever still gives it to them. Every check is
 * PortalAccessGraph's: only people the editor may manage, only powers the editor holds, never
 * wider than the editor's own reach. Display only for now: no page follows these rules yet.
 */
final class PortalAccessControl
{
    public const ON = 'on';

    public const OFF = 'off';

    public const REACH = 'reach';

    public const LIST = 'list';

    public const LIST_NONE = 'none';

    public const LIST_ALLOW = 'allow';

    public const LIST_BLOCK = 'block';

    public function __construct(private readonly PortalAudit $audit) {}

    /**
     * One person's standing on every power the editor holds: whether they have it, from where,
     * what the editor gave or limited, and what they would keep without the editor's gift.
     *
     * @return array{section: string, resource: string, tier: string, employee: array<string, mixed>, data: list<array<string, mixed>>}
     */
    public function show(Employee $editor, int $targetId, Request $request): array
    {
        $graph = PortalAccessGraph::forViewer($editor, $request);
        $editorId = (int) $editor->getKey();
        $asDeveloper = $graph->actingTier($editorId) === PortalAccessGraph::TIER_DEVELOPER;
        $target = $this->manageable($graph, $editorId, $targetId);
        $theirs = $graph->effective($targetId);
        $grants = $graph->grantsOn($targetId);
        $limits = $graph->limitsOn($targetId);
        $listPeople = [];
        // Rows are yours only in the mode you are in now.
        $yours = static fn (object $row, string $maker): bool => (int) $row->{$maker} === $editorId && (bool) $row->as_developer === $asDeveloper;

        $data = [];
        foreach ($graph->powersFor($editorId, $targetId) as $key => $mine) {
            // Developer tools come only with Developer mode, so the sheet never offers them.
            if ($graph->isDeveloperOnly($key)) {
                continue;
            }
            $row = $graph->catalog()[$key];
            $list = null;
            if ($graph->supportsList($key)) {
                $listPeople[$key] = $this->visiblePeople($editor, $key, $request);
                $list = $this->listStanding($graph, $editorId, $asDeveloper, $targetId, $key, array_keys($listPeople[$key]));
            }
            $their = $theirs[$key] ?? null;
            $myGrant = $this->first($grants, static fn (object $grant): bool => $grant->permission_key === $key && $yours($grant, 'granted_by'));
            $limitedByYou = false;
            $limiters = [];
            foreach ($limits as $limit) {
                if ($limit->permission_key !== $key || $graph->validMaker((int) $limit->limited_by, $targetId, $key, (bool) $limit->as_developer) === null) {
                    continue;
                }
                if ($yours($limit, 'limited_by')) {
                    $limitedByYou = true;
                } elseif ((int) $limit->limited_by !== $editorId) {
                    $limiters[] = (int) $limit->limited_by;
                }
            }
            $without = $graph->without($targetId, $key, $editorId, $asDeveloper);

            $data[] = [
                'key' => $key,
                'parentKey' => $row->parent_key,
                'label' => $row->label,
                'description' => $row->description,
                'supportsReach' => (bool) $row->supports_reach,
                'yourReach' => $mine['reach'],
                'yourExecutives' => $mine['executives'],
                'holds' => $their !== null,
                'reach' => $their['reach'] ?? null,
                'includeExecutives' => $their['executives'] ?? false,
                'fromRole' => $their['fromRole'] ?? false,
                'givenBy' => $graph->names($their['givenBy'] ?? []),
                'givenByYou' => $myGrant === null ? null : [
                    'reach' => $myGrant->reach,
                    'includeExecutives' => (bool) $myGrant->include_executives,
                ],
                'limitedByYou' => $limitedByYou,
                'limitedBy' => $graph->names(array_values(array_unique($limiters))),
                // What the editor did here in Developer mode stays theirs to change only there.
                'lockedToDeveloperMode' => ! $asDeveloper && $this->madeInDeveloperMode($graph, $editorId, $targetId, $key),
                'canHold' => $graph->canHold($targetId, $key),
                'heldWithoutYou' => $without['holds'],
                'reachWithoutYou' => $without['reach'],
                'supportsList' => $list !== null,
                'list' => $list,
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'access',
            'tier' => $graph->actingTier($editorId),
            'employee' => $this->person($target),
            'data' => $data,
            // Per list rule, whoever the editor can see there: the only people they may list.
            'listPeople' => array_map(
                static fn (array $people): array => array_map(
                    static fn (int $id, string $name): array => ['id' => (string) $id, 'name' => $name],
                    array_keys($people),
                    array_values($people),
                ),
                $listPeople,
            ),
        ];
    }

    /**
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool, mode?: string|null, people?: list<int>}  $change
     * @return array{section: string, resource: string, tier: string, employee: array<string, mixed>, data: list<array<string, mixed>>}
     */
    public function change(Employee $editor, int $targetId, array $change, Request $request): array
    {
        $graph = PortalAccessGraph::forViewer($editor, $request);
        $live = $graph->actingTier((int) $editor->getKey()) === PortalAccessGraph::TIER_DEVELOPER ? (int) $editor->getKey() : null;
        $editorId = (int) $editor->getKey();
        $target = $this->manageable($graph, $editorId, $targetId);
        $key = $change['key'];
        if ($graph->isDeveloperOnly($key)) {
            abort(422, 'Developer tools come only with Developer mode, so nobody can give or limit them.');
        }
        if (! isset($graph->powersFor($editorId, $targetId)[$key])) {
            abort(404, 'You do not hold that power.');
        }
        // Default never undoes Developer mode: whatever the editor set there is changed only there.
        if ($live === null && $this->madeInDeveloperMode($graph, $editorId, $targetId, $key)) {
            abort(422, 'You made this in Developer mode. Switch to Developer mode to change it.');
        }

        $message = match ($change['action']) {
            self::ON => $this->switchOn($editorId, $live, $targetId, $key, $change),
            self::OFF => $this->switchOff($editorId, $live, $targetId, $key),
            self::LIST => $this->setList($graph, $editor, $live !== null, $targetId, $key, $change, $request),
            default => $this->reach($graph, $editorId, $live !== null, $targetId, $key, $change),
        };
        // Whose Access Rules each viewer may open is part of the cached Users roster.
        PortalCache::bump();

        $this->audit->record(
            $editor,
            PortalLogAction::PATCH,
            'manage.access',
            sprintf($message, PortalAccessGraph::name($target), $graph->path($key)),
            (string) $targetId,
            $request,
            ['employeeId' => $targetId, 'key' => $key, 'action' => $change['action'], 'reach' => $change['reach'] ?? null, 'mode' => $change['mode'] ?? null, 'people' => $change['people'] ?? null],
        );

        return $this->show($editor, $targetId, $request);
    }

    /**
     * Lifts the editor's limits on the power and the pages above it (a developer lifts everyone's),
     * then gives it if the person still lacks it. Someone else's limit still standing is refused
     * by name, because giving under it would change nothing.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
     */
    private function switchOn(int $editorId, ?int $live, int $targetId, string $key, array $change): string
    {
        $before = PortalAccessGraph::load($live);
        if (! $before->canHold($targetId, $key)) {
            abort(422, 'Only an administrator or above can hold that power.');
        }
        $lift = DB::connection('portal')->table('access_limits')
            ->where('employee_id', $targetId)
            ->whereIn('permission_key', [...$before->ancestors($key), $key]);
        if ($live === null) {
            $lift->where('limited_by', $editorId)->where('as_developer', false);
        }
        $lift->delete();

        $graph = PortalAccessGraph::load($live);
        if (isset($graph->effective($targetId)[$key])) {
            return '{UserName|You} switched on %2$s for %1$s';
        }

        $blockers = [];
        foreach ([...$graph->ancestors($key), $key] as $current) {
            foreach ($graph->limitsOn($targetId) as $limit) {
                if ($limit->permission_key === $current && $graph->validMaker((int) $limit->limited_by, $targetId, $current, (bool) $limit->as_developer) !== null) {
                    $blockers[] = (int) $limit->limited_by;
                }
            }
        }
        if ($blockers !== []) {
            abort(422, implode(', ', $graph->names(array_values(array_unique($blockers)))).' limited that power, so it cannot be switched on.');
        }

        $this->give($graph, $editorId, $live !== null, $targetId, $key, $change);

        return '{UserName|You} switched on %2$s for %1$s';
    }

    /**
     * Takes back the editor's gift of the power and everything under it, then limits the power if
     * their role or someone else still gives it.
     */
    private function switchOff(int $editorId, ?int $live, int $targetId, string $key): string
    {
        $before = PortalAccessGraph::load($live);
        DB::connection('portal')->table('access_grants')
            ->where('employee_id', $targetId)
            ->where('granted_by', $editorId)
            ->where('as_developer', $live !== null)
            ->whereIn('permission_key', $before->subtree($key))
            ->delete();

        if (isset(PortalAccessGraph::load($live)->effective($targetId)[$key])) {
            DB::connection('portal')->table('access_limits')->insertOrIgnore([
                'employee_id' => $targetId,
                'permission_key' => $key,
                'limited_by' => $editorId,
                'as_developer' => $live !== null,
            ]);
        }

        return '{UserName|You} switched off %2$s for %1$s';
    }

    /**
     * Re-gives a power at a new reach. Only reshapes the editor's own gift: a reach narrower than
     * what their role already grants changes nothing, since limits are all or nothing.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
     */
    private function reach(PortalAccessGraph $graph, int $editorId, bool $asDeveloper, int $targetId, string $key, array $change): string
    {
        if (! isset($graph->effective($targetId)[$key])) {
            abort(422, 'Switch the power on before choosing how far it reaches.');
        }
        $this->give($graph, $editorId, $asDeveloper, $targetId, $key, $change);

        return '{UserName|You} changed how far %2$s reaches for %1$s';
    }

    /**
     * Gives the power with every tab under it the editor holds, and any page above it the person
     * lacks, so a tab never arrives without its page. Each is capped at the editor's own reach.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
     */
    private function give(PortalAccessGraph $graph, int $editorId, bool $asDeveloper, int $targetId, string $key, array $change): void
    {
        $mine = $graph->powersFor($editorId, $targetId);
        $held = $mine[$key];
        $reach = null;
        if ((bool) $graph->catalog()[$key]->supports_reach) {
            $reach = $change['reach'] ?? $held['reach'];
            if (PortalAccessGraph::reachRank($reach) === 0) {
                abort(422, 'Choose how far the power reaches.');
            }
            if (PortalAccessGraph::reachRank($reach) > PortalAccessGraph::reachRank($held['reach'])) {
                abort(422, 'You cannot give a power wider than you hold it.');
            }
        }
        $executives = (bool) ($change['includeExecutives'] ?? false);
        if ($executives && ! $held['executives']) {
            abort(422, 'You cannot include executives when you cannot see them yourself.');
        }

        $theirs = $graph->effective($targetId);
        $missingPages = array_filter($graph->ancestors($key), static fn (string $page): bool => ! isset($theirs[$page]));
        // A tab their role cannot hold (Manage other admins, for a member) stays behind, the rest goes;
        // an ignore power is given only on its own, never as a tab of the rule whose lists it skips.
        $branch = array_filter($graph->subtree($key), static fn (string $tab): bool => isset($mine[$tab]) && $graph->canHold($targetId, $tab)
            && ($tab === $key || ! str_ends_with($tab, '.'.PortalRules::UNLISTED)));
        foreach ([...$missingPages, ...$branch] as $current) {
            $currentReach = (bool) $graph->catalog()[$current]->supports_reach
                ? $this->narrower($reach ?? $mine[$current]['reach'], $mine[$current]['reach'])
                : null;
            DB::connection('portal')->table('access_grants')->updateOrInsert(
                ['employee_id' => $targetId, 'permission_key' => $current, 'granted_by' => $editorId, 'as_developer' => $asDeveloper],
                ['reach' => $currentReach, 'include_executives' => $executives && $mine[$current]['executives']],
            );
        }
    }

    /**
     * Replaces the editor's white or black list on the person's rule. Only people the editor can see
     * may be listed; anyone already listed whom the editor can no longer see stays, since the editor
     * cannot judge them, unless the list changes kind or is cleared.
     *
     * @param  array{key: string, action: string, mode?: string|null, people?: list<int>}  $change
     */
    private function setList(PortalAccessGraph $graph, Employee $editor, bool $asDeveloper, int $targetId, string $key, array $change, Request $request): string
    {
        if (! $graph->supportsList($key)) {
            abort(422, 'That power has no white or black list.');
        }
        if (! isset($graph->effective($targetId)[$key])) {
            abort(422, 'Switch the power on before choosing who it shows.');
        }
        $mode = $change['mode'] ?? self::LIST_NONE;
        $people = array_values(array_unique(array_map('intval', $change['people'] ?? [])));
        if ($mode !== self::LIST_NONE && $people === []) {
            abort(422, 'Choose at least one person for the list.');
        }
        $visible = array_keys($this->visiblePeople($editor, $key, $request));
        if (array_diff($people, $visible) !== []) {
            abort(422, 'You can only list people you can see yourself.');
        }

        $mine = fn () => DB::connection('portal')->table('access_lists')
            ->where('employee_id', $targetId)
            ->where('permission_key', $key)
            ->where('set_by', $editor->getKey())
            ->where('as_developer', $asDeveloper);
        $current = $mine()->value('mode');
        if ($mode === self::LIST_NONE || ($current !== null && $current !== $mode)) {
            $mine()->delete();
        } else {
            $mine()->whereIn('subject_id', $visible)->delete();
        }
        if ($mode !== self::LIST_NONE) {
            DB::connection('portal')->table('access_lists')->insertOrIgnore(array_map(static fn (int $id): array => [
                'employee_id' => $targetId,
                'permission_key' => $key,
                'mode' => $mode,
                'subject_id' => $id,
                'set_by' => $editor->getKey(),
                'as_developer' => $asDeveloper,
            ], $people));
        }

        $count = count($people).' '.(count($people) === 1 ? 'person' : 'people');

        return match ($mode) {
            self::LIST_ALLOW => '{UserName|You} set a white list of '.$count.' on %2$s for %1$s',
            self::LIST_BLOCK => '{UserName|You} set a black list of '.$count.' on %2$s for %1$s',
            default => '{UserName|You} removed the white or black list on %2$s for %1$s',
        };
    }

    /**
     * Whoever the editor can open through the rule right now, id => name: the people they may list.
     *
     * @return array<int, string>
     */
    private function visiblePeople(Employee $editor, string $key, Request $request): array
    {
        $people = [];
        foreach (PortalRules::people($editor, $request, $key) as $person) {
            $people[(int) $person->getKey()] = PortalAccessGraph::name($person);
        }

        return $people;
    }

    /**
     * The editor's own list on the person's rule (only the people the editor can see), whoever else
     * narrowed it, and whether the person ignores lists anyway.
     *
     * @param  list<int>  $visible
     * @return array{mode: ?string, people: list<string>, others: list<array{name: string, mode: string}>, ignores: bool}
     */
    private function listStanding(PortalAccessGraph $graph, int $editorId, bool $asDeveloper, int $targetId, string $key, array $visible): array
    {
        $mine = null;
        $others = [];
        foreach ($graph->listsOn($targetId, $key) as $list) {
            if ($list['maker'] === $editorId && $list['asDeveloper'] === $asDeveloper) {
                $mine = $list;
            } elseif ($list['maker'] !== $editorId) {
                $others[] = ['name' => $graph->names([$list['maker']])[0] ?? '', 'mode' => $list['mode']];
            }
        }

        return [
            'mode' => $mine['mode'] ?? null,
            'people' => array_map('strval', array_values(array_intersect($mine['ids'] ?? [], $visible))),
            'others' => $others,
            'ignores' => isset($graph->effective($targetId)[$key.'.'.PortalRules::UNLISTED]),
        ];
    }

    /**
     * Whether the editor gave, limited or listed this power, or anything under it, for the person
     * while in Developer mode.
     */
    private function madeInDeveloperMode(PortalAccessGraph $graph, int $editorId, int $targetId, string $key): bool
    {
        $keys = $graph->subtree($key);
        $byEditor = static fn (object $row, string $maker): bool => (int) $row->{$maker} === $editorId
            && (bool) $row->as_developer && in_array($row->permission_key, $keys, true);
        foreach ($graph->grantsOn($targetId) as $grant) {
            if ($byEditor($grant, 'granted_by')) {
                return true;
            }
        }
        foreach ($graph->limitsOn($targetId) as $limit) {
            if ($byEditor($limit, 'limited_by')) {
                return true;
            }
        }

        return DB::connection('portal')->table('access_lists')
            ->where('employee_id', $targetId)
            ->where('set_by', $editorId)
            ->where('as_developer', true)
            ->whereIn('permission_key', $keys)
            ->exists();
    }

    private function manageable(PortalAccessGraph $graph, int $editorId, int $targetId): Employee
    {
        $target = $graph->employee($targetId);
        if ($target === null || ! $graph->canManage($editorId, $targetId)) {
            abort(404, 'That person is not someone you manage.');
        }

        return $target;
    }

    /**
     * @return array{id: string, name: string, idNo: ?string, department: ?string, tier: string}
     */
    private function person(Employee $employee): array
    {
        return [
            'id' => (string) $employee->getKey(),
            'name' => PortalAccessGraph::name($employee),
            'idNo' => is_string($employee->id_no) && $employee->id_no !== '' ? $employee->id_no : null,
            'department' => is_string($employee->department) && trim($employee->department) !== '' ? trim($employee->department) : null,
            'tier' => PortalAccessGraph::tier($employee),
        ];
    }

    private function narrower(?string $a, ?string $b): ?string
    {
        return PortalAccessGraph::reachRank($a) <= PortalAccessGraph::reachRank($b) ? $a : $b;
    }

    /**
     * @param  list<object>  $rows
     */
    private function first(array $rows, callable $match): ?object
    {
        foreach ($rows as $row) {
            if ($match($row)) {
                return $row;
            }
        }

        return null;
    }
}
