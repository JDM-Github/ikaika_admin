<?php

namespace App\Support\Workspace;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saved views: a named grid state, kept so a daily question is asked once.
 *
 * The grid already puts its entire state in the query string, so a view is that
 * string plus a name. Nothing is interpreted on the way in beyond an allow-list of
 * parameters -- the grid re-validates every column and operator when the view is
 * opened, exactly as it does for a hand-typed URL.
 */
final class WorkspaceViews
{
    /**
     * Query parameters a view may carry. page is deliberately absent: a view is a
     * question, not a scroll position.
     *
     * @var list<string>
     */
    private const KEEP = ['cols', 'sort', 'q', 'per_page', 'filter'];

    private const MAX_NAME = 100;

    private const MAX_QUERY = 2000;

    /**
     * Views one person may keep per table, shared and private together.
     */
    private const MAX_PER_TABLE = 30;

    /**
     * Views visible to this actor for one table: their own first, then shared ones.
     *
     * @return list<array{id: int, name: string, query: string, shared: bool, owner_id_no: ?string, mine: bool}>
     */
    public function list(string $product, string $table, WorkspaceAccess $access): array
    {
        $rows = $this->query()
            ->where('product', $product)
            ->where('table_name', $table)
            ->where(function ($inner) use ($access): void {
                $inner->where('owner_id', $access->actorId())->orWhere('shared', true);
            })
            ->orderByDesc('shared')
            ->orderBy('name')
            ->get();

        $views = [];
        foreach ($rows as $row) {
            $views[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'query' => (string) $row->query,
                'shared' => (bool) $row->shared,
                'owner_id_no' => $row->owner_id_no === null ? null : (string) $row->owner_id_no,
                'mine' => (int) $row->owner_id === $access->actorId(),
            ];
        }

        usort($views, function (array $a, array $b): int {
            return [$a['mine'] ? 0 : 1, strtolower($a['name'])] <=> [$b['mine'] ? 0 : 1, strtolower($b['name'])];
        });

        return $views;
    }

    /**
     * Create or replace one of the actor's own views. Saving the same name twice
     * updates it, which is what "save" means to someone adjusting a filter.
     *
     * @return array{id: int, name: string, query: string, shared: bool, owner_id_no: ?string, mine: bool}
     */
    public function save(
        string $product,
        string $table,
        string $name,
        string $query,
        bool $shared,
        WorkspaceAccess $access,
    ): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            abort(422, 'A view needs a name of 1 to '.self::MAX_NAME.' characters.');
        }

        if ($shared && ! $access->canShareViews()) {
            abort(403, 'Only administrators can share a view with everyone.');
        }

        $clean = $this->sanitise($query);

        $existing = $this->query()
            ->where('owner_id', $access->actorId())
            ->where('product', $product)
            ->where('table_name', $table)
            ->where('name', $name)
            ->first();

        if ($existing === null) {
            $count = $this->query()->where('owner_id', $access->actorId())->where('product', $product)->where('table_name', $table)->count();
            if ($count >= self::MAX_PER_TABLE) {
                abort(422, 'You already have '.self::MAX_PER_TABLE.' views on this table. Delete one first.');
            }
        }

        $now = Carbon::now();

        if ($existing !== null) {
            $this->query()->where('id', $existing->id)->update([
                'query' => $clean,
                'shared' => $shared,
                'updated_at' => $now,
            ]);

            $id = (int) $existing->id;
        } else {
            $id = (int) $this->query()->insertGetId([
                'product' => $product,
                'table_name' => $table,
                'name' => $name,
                'query' => $clean,
                'owner_id' => $access->actorId(),
                'owner_id_no' => $access->actorIdNo(),
                'shared' => $shared,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return [
            'id' => $id,
            'name' => $name,
            'query' => $clean,
            'shared' => $shared,
            'owner_id_no' => $access->actorIdNo(),
            'mine' => true,
        ];
    }

    /**
     * Own views always; a shared view only by someone who could have shared it.
     */
    public function delete(int $id, WorkspaceAccess $access): void
    {
        $view = $this->query()->where('id', $id)->first();
        if ($view === null) {
            abort(404, 'That view no longer exists.');
        }

        $mine = (int) $view->owner_id === $access->actorId();
        if (! $mine && ! ((bool) $view->shared && $access->canShareViews())) {
            abort(403, 'That view belongs to someone else.');
        }

        $this->query()->where('id', $id)->delete();
    }

    /**
     * Keep only the parameters that describe the question, and drop a filter whose
     * column or expression is not a plain string. The grid re-checks both against the
     * real schema when the view is opened.
     */
    private function sanitise(string $query): string
    {
        parse_str(ltrim($query, '?'), $parsed);

        $kept = [];
        foreach (self::KEEP as $parameter) {
            if (! array_key_exists($parameter, $parsed)) {
                continue;
            }

            if ($parameter !== 'filter') {
                if (is_string($parsed[$parameter])) {
                    $kept[$parameter] = $parsed[$parameter];
                }

                continue;
            }

            if (! is_array($parsed['filter'])) {
                continue;
            }

            $filters = [];
            foreach ($parsed['filter'] as $column => $expression) {
                if (is_string($column) && is_string($expression) && $expression !== '') {
                    $filters[$column] = $expression;
                }
            }

            if ($filters !== []) {
                $kept['filter'] = $filters;
            }
        }

        $clean = http_build_query($kept);
        if (strlen($clean) > self::MAX_QUERY) {
            abort(422, 'That view carries too many filters to save.');
        }

        return $clean;
    }

    private function query(): Builder
    {
        return DB::connection('core')->table('workspace_views');
    }
}
