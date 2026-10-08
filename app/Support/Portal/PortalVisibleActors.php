<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Whose records an Administration list shows (All Logs, All Actions): everyone the rule's reach
 * covers, executives only with its executives flag, always the viewer, and another developer only
 * to someone holding See developers' activity. Records tied to no employee show only at every-
 * department reach, since no reach can place them.
 */
final class PortalVisibleActors
{
    /**
     * @return array{ids: list<int>, unknownToo: bool, everyone: list<int>}
     */
    public static function for(Employee $viewer, Request $request, string $key): array
    {
        $viewerId = (int) $viewer->getKey();
        $reach = PortalRules::reach($viewer, $request, $key);
        $withExecutives = (bool) (PortalAccessGraph::forViewer($viewer, $request)->effective($viewerId)[$key]['executives'] ?? false);
        $seesDevelopers = PortalRules::holds($viewer, $request, PortalRules::DEVELOPERS_ACTIVITY);

        $employees = Employee::query()->get(['id', 'department', 'role', 'role_level', 'is_developer']);
        $ids = $employees
            ->filter(static fn (Employee $person): bool => (int) $person->getKey() === $viewerId
                || (PortalRules::covers($reach, $viewer, $person, $withExecutives) && ($seesDevelopers || ! (bool) $person->is_developer)))
            ->map(static fn (Employee $person): int => (int) $person->getKey())
            ->sort()
            ->values()
            ->all();

        return [
            'ids' => $ids,
            'unknownToo' => $reach === 'all',
            'everyone' => $employees->map(static fn (Employee $person): int => (int) $person->getKey())->sort()->values()->all(),
        ];
    }

    /**
     * Keeps the rows whose person is visible; with unknownToo, also rows tied to nobody on the roster.
     *
     * @param  array{ids: list<int>, unknownToo: bool, everyone: list<int>}  $visible
     */
    public static function apply(Builder $query, array $visible, string $column): void
    {
        if (! $visible['unknownToo']) {
            $query->whereIn($column, $visible['ids']);

            return;
        }

        $query->where(static fn (Builder $inner) => $inner
            ->whereIn($column, $visible['ids'])
            ->orWhereNull($column)
            ->orWhereNotIn($column, $visible['everyone']));
    }
}
