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

    public const OVERRIDE = 'override';

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
        // In Default only what you made in Default is yours to change; Developer mode owns both.
        $yours = static fn (object $row, string $maker): bool => (int) $row->{$maker} === $editorId && ($asDeveloper || ! (bool) $row->as_developer);

        $data = [];
        foreach ($graph->powersFor($editorId, $targetId) as $key => $mine) {
            // Developer-only powers come only with Developer mode, a power their role or department can never hold is not
            // theirs to see, and one that always stays on (the Access page) is nothing to give or limit.
            if ($graph->isDeveloperOnly($key) || $this->alwaysOn($graph, $key) || (! $graph->canHold($targetId, $key) && ! isset($theirs[$key]))) {
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
                    $limiters[] = ['id' => (int) $limit->limited_by, 'asDeveloper' => (bool) $limit->as_developer];
                }
            }
            $without = $graph->without($targetId, $key, $editorId, $asDeveloper ? null : false);
            $throughOthers = $this->heldThroughOthers($graph, $editorId, $asDeveloper, $targetId, $key);
            $limitedHere = $limiters !== [];
            // In Developer mode, what you did in Default is yours too, and Override turns it into a Developer mode act.
            $fromDefault = $asDeveloper && $this->yoursFromDefault($graph, $editorId, $targetId, $key);

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
                'fromDefault' => $their['personal'] ?? false,
                'givenBy' => $their === null ? [] : $graph->makerNames(PortalAccessGraph::givers($their), $asDeveloper),
                'givenByYou' => $myGrant === null ? null : [
                    'reach' => $myGrant->reach,
                    'includeExecutives' => (bool) $myGrant->include_executives,
                ],
                'limitedByYou' => $limitedByYou,
                'limitedBy' => $graph->makerNames($limiters, $asDeveloper),
                // Someone else holds it in place, here or on a page above; only Override in Developer mode changes it.
                'lockedByOthers' => $throughOthers || $limitedHere || $this->limitedAbove($graph, $editorId, $targetId, $key),
                'canOverride' => $asDeveloper && ($throughOthers || $limitedHere || $fromDefault),
                'yoursFromDefault' => $fromDefault,
                // What the editor did here in Developer mode stays theirs to change only there.
                'lockedToDeveloperMode' => ! $asDeveloper && $this->madeInDeveloperMode($graph, $editorId, $targetId, $key),
                'canHold' => $graph->canHold($targetId, $key),
                // Carried by another power they hold: on and locked until that one goes.
                'impliedBy' => isset($their['impliedBy']) ? $graph->catalog()[$their['impliedBy']]->label : null,
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
        if (($change['reach'] ?? null) === 'lead' && ! PortalProjectPowers::supportsLeadReach($key)) {
            abort(422, 'Only Edit projects, Manage scopes and Delete projects reach the projects you lead.');
        }
        if ($graph->isDeveloperOnly($key)) {
            abort(422, 'That power comes only with Developer mode, so nobody can give or limit it.');
        }
        if ($this->alwaysOn($graph, $key)) {
            abort(422, 'That power always stays on, so nobody can give or limit it.');
        }
        if (! isset($graph->powersFor($editorId, $targetId)[$key])) {
            abort(404, 'You do not hold that power.');
        }
        // Default never undoes Developer mode: whatever the editor set there is changed only there.
        if ($live === null && $this->madeInDeveloperMode($graph, $editorId, $targetId, $key)) {
            abort(422, 'You made this in Developer mode. Switch to Developer mode to change it.');
        }
        if ($change['action'] === self::OVERRIDE && $live === null) {
            abort(403, 'Only a developer in Developer mode can override.');
        }
        $carrier = $graph->effective($targetId)[$key]['impliedBy'] ?? null;
        if ($carrier !== null && $change['action'] !== self::ON) {
            abort(422, 'That power comes with '.$graph->catalog()[$carrier]->label.', so it stays on while they hold it. Switch that off first.');
        }
        // Held on only through someone else's gift: nobody but a developer's Override moves it.
        if (in_array($change['action'], [self::OFF, self::REACH], true) && $this->heldThroughOthers($graph, $editorId, $live !== null, $targetId, $key)) {
            abort(422, 'Someone else gave this power, so only a developer can change it, with Override.');
        }

        $message = match ($change['action']) {
            self::ON => $this->switchOn($editorId, $live, $targetId, $key, $change),
            self::OFF => $this->switchOff($editorId, $live, $targetId, $key),
            self::LIST => $this->setList($graph, $editor, $live !== null, $targetId, $key, $change, $request),
            self::OVERRIDE => $this->override($editorId, (int) $live, $targetId, $key, $change),
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
            ['employeeId' => $targetId, 'key' => $key, 'action' => $change['action'], 'reach' => $change['reach'] ?? null, 'mode' => $change['mode'] ?? null, 'people' => $change['people'] ?? null, 'on' => $change['on'] ?? null],
        );

        return $this->show($editor, $targetId, $request);
    }

    /**
     * Lifts the editor's own limits on the power and the pages above it (in Developer mode their
     * Default ones too), then gives it if the person still lacks it. Someone else's limit still
     * standing is refused by name: only Override lifts it.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
     */
    private function switchOn(int $editorId, ?int $live, int $targetId, string $key, array $change): string
    {
        $before = PortalAccessGraph::load($live);
        $refusal = $before->holdRefusal($targetId, $key);
        if ($refusal !== null) {
            abort(422, $refusal);
        }
        $lift = DB::connection('portal')->table('access_limits')
            ->where('employee_id', $targetId)
            ->whereIn('permission_key', [...$before->ancestors($key), $key]);
        $lift->where('limited_by', $editorId)
            ->when($live === null, static fn ($query) => $query->where('as_developer', false))
            ->delete();

        $graph = PortalAccessGraph::load($live);
        if (isset($graph->effective($targetId)[$key])) {
            return '{UserName|You} switched on %2$s for %1$s';
        }

        $blockers = [];
        foreach ([...$graph->ancestors($key), $key] as $current) {
            foreach ($graph->limitsOn($targetId) as $limit) {
                if ($limit->permission_key === $current && $graph->validMaker((int) $limit->limited_by, $targetId, $current, (bool) $limit->as_developer) !== null) {
                    $blockers[] = ['id' => (int) $limit->limited_by, 'asDeveloper' => (bool) $limit->as_developer];
                }
            }
        }
        if ($blockers !== []) {
            abort(422, ucfirst(implode(', ', $graph->makerNames($blockers, $live !== null))).' limited that power, so it cannot be switched on.');
        }

        $this->give($graph, $editorId, $live !== null, $targetId, $key, $change);

        return '{UserName|You} switched on %2$s for %1$s';
    }

    /**
     * Takes back the editor's gift of the power and everything under it, then limits the power if
     * their role still gives it. Someone else's gift stays: only Override takes that.
     */
    private function switchOff(int $editorId, ?int $live, int $targetId, string $key): string
    {
        $before = PortalAccessGraph::load($live);
        // Developer mode takes back what the editor gave in either mode; Default only its own.
        DB::connection('portal')->table('access_grants')
            ->where('employee_id', $targetId)
            ->where('granted_by', $editorId)
            ->when($live === null, static fn ($query) => $query->where('as_developer', false))
            ->whereIn('permission_key', $before->subtree($key))
            ->delete();

        if ((PortalAccessGraph::load($live)->effective($targetId)[$key]['fromRole'] ?? false) === true) {
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
        // an ignore power or See developers' activity is given only on its own, never as a tab.
        $branch = array_filter($graph->subtree($key), static fn (string $tab): bool => isset($mine[$tab]) && $graph->canHold($targetId, $tab)
            && ($tab === $key || ! PortalRules::givenAlone($tab)));
        foreach ([...$missingPages, ...$branch] as $current) {
            $currentReach = (bool) $graph->catalog()[$current]->supports_reach
                ? $this->narrower($reach ?? $mine[$current]['reach'], $mine[$current]['reach'])
                : null;
            // A gift made in Developer mode replaces the editor's Default gift of the same power.
            if ($asDeveloper) {
                DB::connection('portal')->table('access_grants')
                    ->where(['employee_id' => $targetId, 'permission_key' => $current, 'granted_by' => $editorId, 'as_developer' => false])
                    ->delete();
            }
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
            // Developer mode replaces the editor's lists from both modes with one Developer mode list.
            ->when(! $asDeveloper, static fn ($query) => $query->where('as_developer', false));
        $current = $mine()->distinct()->pluck('mode')->all();
        if ($mode === self::LIST_NONE || array_diff($current, [$mode]) !== []) {
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
        // The list made in this mode, else the one from the other mode: read only in Default, replaced on save in Developer mode.
        $byMode = [];
        $others = [];
        foreach ($graph->listsOn($targetId, $key) as $list) {
            if ($list['maker'] === $editorId) {
                $byMode[$list['asDeveloper'] ? 'dev' : 'role'] = $list;
            } else {
                $others[] = ['name' => $graph->makerNames([['id' => $list['maker'], 'asDeveloper' => $list['asDeveloper']]], $asDeveloper)[0] ?? '', 'mode' => $list['mode']];
            }
        }
        $mine = $asDeveloper ? ($byMode['dev'] ?? $byMode['role'] ?? null) : ($byMode['role'] ?? $byMode['dev'] ?? null);

        return [
            'mode' => $mine['mode'] ?? null,
            'people' => array_map('strval', array_values(array_intersect($mine['ids'] ?? [], $visible))),
            'others' => $others,
            'ignores' => isset($graph->effective($targetId)[$key.'.'.PortalRules::UNLISTED]),
        ];
    }

    /**
     * A developer takes the power and everything under it over for the person: every earlier gift
     * and limit on that branch goes, and what the switch shows becomes theirs. On gives it at the
     * reach sent (or the one they had), off limits it where their role would still give it.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool, on?: bool|null}  $change
     */
    private function override(int $editorId, int $live, int $targetId, string $key, array $change): string
    {
        $before = PortalAccessGraph::load($live);
        $on = (bool) ($change['on'] ?? false);
        $refusal = $on ? $before->holdRefusal($targetId, $key) : null;
        if ($refusal !== null) {
            abort(422, $refusal);
        }
        if ($on && $this->limitedAbove($before, $editorId, $targetId, $key, true)) {
            abort(422, 'A page above this power is limited. Override that page first.');
        }
        $keys = $before->subtree($key);
        $reach = $change['reach'] ?? ($before->effective($targetId)[$key]['reach'] ?? null);
        DB::connection('portal')->table('access_grants')->where('employee_id', $targetId)->whereIn('permission_key', $keys)->delete();
        DB::connection('portal')->table('access_limits')->where('employee_id', $targetId)->whereIn('permission_key', $keys)->delete();

        $after = PortalAccessGraph::load($live);
        if ($on) {
            $this->give($after, $editorId, true, $targetId, $key, [...$change, 'reach' => $reach]);
        } elseif (isset($after->effective($targetId)[$key])) {
            DB::connection('portal')->table('access_limits')->insertOrIgnore([
                'employee_id' => $targetId,
                'permission_key' => $key,
                'limited_by' => $editorId,
                'as_developer' => true,
            ]);
        }

        return '{UserName|You} overrode %2$s for %1$s';
    }

    // On only through gifts, none of them the editor's own in a mode they may change: someone else holds it on.
    private function heldThroughOthers(PortalAccessGraph $graph, int $editorId, bool $asDeveloper, int $targetId, string $key): bool
    {
        $theirs = $graph->effective($targetId)[$key] ?? null;
        if ($theirs === null || $theirs['fromRole']) {
            return false;
        }
        foreach ($graph->grantsOn($targetId) as $grant) {
            if ($grant->permission_key === $key && (int) $grant->granted_by === $editorId && ($asDeveloper || ! (bool) $grant->as_developer)) {
                return false;
            }
        }

        return array_diff($theirs['givenBy'], [$editorId]) !== [];
    }

    // The editor's own Default gift or limit on exactly this power.
    private function yoursFromDefault(PortalAccessGraph $graph, int $editorId, int $targetId, string $key): bool
    {
        $mine = static fn (object $row, string $maker): bool => $row->permission_key === $key
            && (int) $row->{$maker} === $editorId && ! (bool) $row->as_developer;
        foreach ($graph->grantsOn($targetId) as $grant) {
            if ($mine($grant, 'granted_by')) {
                return true;
            }
        }
        foreach ($graph->limitsOn($targetId) as $limit) {
            if ($mine($limit, 'limited_by')) {
                return true;
            }
        }

        return false;
    }

    // Someone else's limit (anyone's, with $anyone) on a page above the power holds it off too.
    private function limitedAbove(PortalAccessGraph $graph, int $editorId, int $targetId, string $key, bool $anyone = false): bool
    {
        $above = $graph->ancestors($key);
        foreach ($graph->limitsOn($targetId) as $limit) {
            if (in_array($limit->permission_key, $above, true) && ($anyone || (int) $limit->limited_by !== $editorId)
                && $graph->validMaker((int) $limit->limited_by, $targetId, (string) $limit->permission_key, (bool) $limit->as_developer) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the editor, in Developer mode, gave or listed this power or anything under it for the
     * person, or limited it, anything under it, or a page above it (which holds it off too).
     */
    private function madeInDeveloperMode(PortalAccessGraph $graph, int $editorId, int $targetId, string $key): bool
    {
        $keys = $graph->subtree($key);
        $byEditor = static fn (object $row, string $maker, array $keys): bool => (int) $row->{$maker} === $editorId
            && (bool) $row->as_developer && in_array($row->permission_key, $keys, true);
        foreach ($graph->grantsOn($targetId) as $grant) {
            if ($byEditor($grant, 'granted_by', $keys)) {
                return true;
            }
        }
        foreach ($graph->limitsOn($targetId) as $limit) {
            if ($byEditor($limit, 'limited_by', [...$graph->ancestors($key), ...$keys])) {
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

    private function alwaysOn(PortalAccessGraph $graph, string $key): bool
    {
        $row = $graph->catalog()[$key] ?? null;

        return $row !== null && ! (bool) $row->can_toggle;
    }
}
