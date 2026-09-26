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
        $target = $this->manageable($graph, $editorId, $targetId);
        $theirs = $graph->effective($targetId);
        $grants = $graph->grantsOn($targetId);
        $limits = $graph->limitsOn($targetId);

        $data = [];
        foreach ($graph->powersFor($editorId, $targetId) as $key => $mine) {
            // Developer tools come only with Developer mode, so the sheet never offers them.
            if ($graph->isDeveloperOnly($key)) {
                continue;
            }
            $row = $graph->catalog()[$key];
            $their = $theirs[$key] ?? null;
            $myGrant = $this->first($grants, static fn (object $grant): bool => $grant->permission_key === $key && (int) $grant->granted_by === $editorId);
            $limiters = [];
            foreach ($limits as $limit) {
                if ($limit->permission_key === $key && $graph->validMaker((int) $limit->limited_by, $targetId, $key) !== null) {
                    $limiters[] = (int) $limit->limited_by;
                }
            }
            $without = $graph->without($targetId, $key, $editorId);

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
                'limitedByYou' => in_array($editorId, $limiters, true),
                'limitedBy' => $graph->names(array_values(array_diff($limiters, [$editorId]))),
                'canHold' => $graph->canHold($targetId, $key),
                'heldWithoutYou' => $without['holds'],
                'reachWithoutYou' => $without['reach'],
            ];
        }

        return [
            'section' => 'manage',
            'resource' => 'access',
            'tier' => $graph->actingTier($editorId),
            'employee' => $this->person($target),
            'data' => $data,
        ];
    }

    /**
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
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

        $message = match ($change['action']) {
            self::ON => $this->switchOn($editorId, $live, $targetId, $key, $change),
            self::OFF => $this->switchOff($editorId, $live, $targetId, $key),
            default => $this->reach($graph, $editorId, $targetId, $key, $change),
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
            ['employeeId' => $targetId, 'key' => $key, 'action' => $change['action'], 'reach' => $change['reach'] ?? null],
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
            $lift->where('limited_by', $editorId);
        }
        $lift->delete();

        $graph = PortalAccessGraph::load($live);
        if (isset($graph->effective($targetId)[$key])) {
            return '{UserName|You} switched on %2$s for %1$s';
        }

        $blockers = [];
        foreach ([...$graph->ancestors($key), $key] as $current) {
            foreach ($graph->limitsOn($targetId) as $limit) {
                if ($limit->permission_key === $current && $graph->validMaker((int) $limit->limited_by, $targetId, $current) !== null) {
                    $blockers[] = (int) $limit->limited_by;
                }
            }
        }
        if ($blockers !== []) {
            abort(422, implode(', ', $graph->names(array_values(array_unique($blockers)))).' limited that power, so it cannot be switched on.');
        }

        $this->give($graph, $editorId, $targetId, $key, $change);

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
            ->whereIn('permission_key', $before->subtree($key))
            ->delete();

        if (isset(PortalAccessGraph::load($live)->effective($targetId)[$key])) {
            DB::connection('portal')->table('access_limits')->insertOrIgnore([
                'employee_id' => $targetId,
                'permission_key' => $key,
                'limited_by' => $editorId,
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
    private function reach(PortalAccessGraph $graph, int $editorId, int $targetId, string $key, array $change): string
    {
        if (! isset($graph->effective($targetId)[$key])) {
            abort(422, 'Switch the power on before choosing how far it reaches.');
        }
        $this->give($graph, $editorId, $targetId, $key, $change);

        return '{UserName|You} changed how far %2$s reaches for %1$s';
    }

    /**
     * Gives the power with every tab under it the editor holds, and any page above it the person
     * lacks, so a tab never arrives without its page. Each is capped at the editor's own reach.
     *
     * @param  array{key: string, action: string, reach?: string|null, includeExecutives?: bool}  $change
     */
    private function give(PortalAccessGraph $graph, int $editorId, int $targetId, string $key, array $change): void
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
        // A tab their role cannot hold (Manage other admins, for a member) stays behind, the rest goes.
        $branch = array_filter($graph->subtree($key), static fn (string $tab): bool => isset($mine[$tab]) && $graph->canHold($targetId, $tab));
        foreach ([...$missingPages, ...$branch] as $current) {
            $currentReach = (bool) $graph->catalog()[$current]->supports_reach
                ? $this->narrower($reach ?? $mine[$current]['reach'], $mine[$current]['reach'])
                : null;
            DB::connection('portal')->table('access_grants')->updateOrInsert(
                ['employee_id' => $targetId, 'permission_key' => $current, 'granted_by' => $editorId],
                ['reach' => $currentReach, 'include_executives' => $executives && $mine[$current]['executives']],
            );
        }
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
