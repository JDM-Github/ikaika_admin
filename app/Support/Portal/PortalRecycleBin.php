<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Recycle;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Administration / Recycle Bin: portal trash from core.recycle.
 *
 * Restore puts a submitted report back and records an add. The original
 * delete action stays on core.actions.
 *
 * Everyone sees their own trash. The "all" view (All Recycle) follows administration.recycle: whoever
 * its reach covers and its white and black lists leave in, never an executive's rows but to the
 * executive. It is view only: people restore their own items, since restoring someone else's could
 * backfire on them.
 */
final class PortalRecycleBin
{
    public const ALL_RULE = 'administration.recycle';

    public const VIEW_ONLY = 'All Recycle is view only: people restore their own items.';

    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    public const SCOPE_OWN = 'own';

    public const SCOPE_ALL = 'all';

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    private const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    public function __construct(
        private readonly PortalViewProjects $projects,
    ) {}

    /**
     * Table column key => SQL columns. Unknown keys fall back to newest first.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE = [
        'item' => ['record_id', 'id'],
        'deleted' => ['created_at', 'id'],
        'employee' => ['deleted_by_id_no', 'id'],
        'purges' => ['purges_at', 'id'],
    ];

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     scope: string,
     *     canViewAll: bool,
     *     data: list<array<string, mixed>>,
     *     employees: list<array<string, mixed>>,
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        $canViewAll = $this->canViewAll($actor, $request);
        $scope = $this->scope($request, $canViewAll);
        // Their own bin follows User / Recycle Bin; All Recycle follows its own rule, reach and lists.
        if ($scope === self::SCOPE_OWN) {
            PortalRules::require($actor, $request, 'user.recycle');
        }
        $visible = $canViewAll && $scope === self::SCOPE_ALL ? $this->visible($actor, $request) : null;
        $employeeId = $this->employeeId($request, $canViewAll, $scope);
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $type = $this->type($request);

        $view = $visible !== null
            ? 'all:'.md5((string) json_encode($visible)).':'.($employeeId ?? 0)
            : 'own:'.$actor->getKey();
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:recycle-bin:'.$version.':'.$view.':'.($type ?? 'all').':'.$page.':'.$perPage.':'.md5($search).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $actor,
            $canViewAll,
            $visible,
            $scope,
            $employeeId,
            $perPage,
            $page,
            $search,
            $sort,
            $dir,
            $type,
        ): array {
            return $this->build($actor, $canViewAll, $visible, $scope, $employeeId, $type, $perPage, $page, $search, $sort, $dir);
        });
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * One generated report from the bin. Same row the actor may restore.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(Employee $actor, int $id, Request $request): array
    {
        $row = $this->itemForActor($actor, $id, $request);
        $this->requireOwn($actor, $row, $request, 'user.recycle');
        $employeeId = is_numeric($row->deleted_by) ? (int) $row->deleted_by : 0;
        $names = $this->employeeDirectory($employeeId > 0 ? [$employeeId] : []);
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:recycle-bin:'.$version.':item:'.$row->getKey();

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($row, $names): array {
            return [
                'section' => 'administration',
                'resource' => 'recycle-bin',
                'data' => [
                    ...PortalRecycleBinPresenter::detail($row, $names),
                    // A deleted project is only stamped, so its details and reported work are still there to show.
                    'project' => PortalProjectDeletion::owns($row) ? $this->projects->binSummary((int) $row->record_id) : null,
                ],
            ];
        });
    }

    // Their own item follows their User / Recycle Bin rules; someone else's follows All Recycle's reach.
    public function requireOwn(Employee $actor, Recycle $row, Request $request, string $key): void
    {
        if ($this->isOwn($actor, $row)) {
            PortalRules::requireOwn($actor, $request, $key);
        }
    }

    // Only their own item comes back: All Recycle only looks.
    public function requireRestorable(Employee $actor, Recycle $row, Request $request): void
    {
        if (! $this->isOwn($actor, $row)) {
            abort(403, self::VIEW_ONLY);
        }
        PortalRules::requireOwn($actor, $request, 'user.recycle.restore');
    }

    private function isOwn(Employee $actor, Recycle $row): bool
    {
        return is_numeric($row->deleted_by) && (int) $row->deleted_by === (int) $actor->getKey();
    }

    /**
     * One portal recycle row the actor may open: their own, or one All Recycle lets them see.
     */
    public function itemForActor(Employee $actor, int $id, Request $request): Recycle
    {
        if ($id <= 0) {
            abort(404, 'That item was not found.');
        }

        $canViewAll = $this->canViewAll($actor, $request);
        $query = Recycle::query()
            ->select(PortalRecycleBinPresenter::restoreColumns())
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->where('id', $id);
        if (! $canViewAll) {
            $query->where('deleted_by', (int) $actor->getKey());
        } else {
            $query->where(fn (Builder $inner) => $inner
                ->where('deleted_by', (int) $actor->getKey())
                ->orWhere(fn (Builder $managed) => PortalVisibleActors::apply($managed, $this->visible($actor, $request), 'deleted_by')));
        }

        $row = $query->first();
        if (! $row instanceof Recycle) {
            abort(404, 'That item was not found.');
        }

        return $row;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     scope: string,
     *     canViewAll: bool,
     *     data: list<array<string, mixed>>,
     *     employees: list<array<string, mixed>>,
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function build(
        Employee $actor,
        bool $canViewAll,
        ?array $visible,
        string $scope,
        ?int $employeeId,
        ?string $type,
        int $perPage,
        int $page,
        string $search,
        string $sort,
        string $dir,
    ): array {
        $scoped = $this->scopedQuery($actor, $visible, $employeeId, $type);
        $totalInBin = (clone $scoped)->toBase()->count();
        // Each type tab's count, before search and whatever tab is open (2026-10-07), so the tabs read as a legend.
        $byType = ['all' => $this->scopedQuery($actor, $visible, $employeeId, null)->toBase()->count()];
        foreach (PortalRecycleBinPresenter::TYPES as $each) {
            $byType[$each] = $this->scopedQuery($actor, $visible, $employeeId, $each)->toBase()->count();
        }

        $this->applySearch($scoped, $search, $canViewAll);
        $this->applyOrder($scoped, $sort, $dir);

        $pageResult = $scoped->paginate($perPage, PortalRecycleBinPresenter::listColumns(), 'page', $page);

        $pageEmployeeIds = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof Recycle && is_numeric($row->deleted_by)) {
                $pageEmployeeIds[] = (int) $row->deleted_by;
            }
        }

        $filterIds = $visible !== null ? $this->deletedByIds($visible) : [];
        $names = $this->employeeDirectory(array_values(array_unique([...$pageEmployeeIds, ...$filterIds])));

        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof Recycle) {
                $rows[] = PortalRecycleBinPresenter::item($row, $names);
            }
        }

        $employees = [];
        if ($canViewAll) {
            $listed = [];
            foreach ($names as $id => $known) {
                if (! in_array($id, $filterIds, true)) {
                    continue;
                }
                $employees[] = PortalRecycleBinPresenter::employee($id, $known['name'], $known['idNo']);
                $listed[$id] = true;
            }
            foreach ($filterIds as $id) {
                if (isset($listed[$id])) {
                    continue;
                }
                $employees[] = PortalRecycleBinPresenter::employee($id, 'Member', null);
            }
        }

        return [
            'section' => 'administration',
            'resource' => 'recycle-bin',
            'scope' => $scope,
            'canViewAll' => $canViewAll,
            'data' => $rows,
            'employees' => $employees,
            'counts' => PortalRecycleBinPresenter::counts($totalInBin, $byType),
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    private function scopedQuery(Employee $actor, ?array $visible, ?int $employeeId, ?string $type): Builder
    {
        $query = Recycle::query()
            ->select(PortalRecycleBinPresenter::listColumns())
            ->where('product', CoreLedger::PRODUCT_PORTAL);

        $this->applyType($query, $type);

        if ($visible === null) {
            $query->where('deleted_by', (int) $actor->getKey());

            return $query;
        }

        PortalVisibleActors::apply($query, $visible, 'deleted_by');
        if ($employeeId !== null) {
            $query->where('deleted_by', $employeeId);
        }

        return $query;
    }

    private function canViewAll(Employee $actor, Request $request): bool
    {
        return PortalRules::holds($actor, $request, self::ALL_RULE);
    }

    /**
     * Whose deleted items All Recycle shows: whoever its reach covers and its lists leave in, and the
     * viewer, never an executive. Former staff count too, since their items outlive them for a while.
     * Rows whose deleter matches no employee show only at every-department reach with no list in
     * the way, since no list can name them.
     *
     * @return array{ids: list<int>, unknownToo: bool, everyone: list<int>}
     */
    private function visible(Employee $actor, Request $request): array
    {
        $reach = PortalRules::reach($actor, $request, self::ALL_RULE);
        $listed = PortalRules::listed($actor, $request, self::ALL_RULE);
        $employees = Employee::query()->get(['id', 'department', 'role', 'role_level']);
        $ids = $employees
            ->filter(static fn (Employee $person): bool => ! PortalRole::isExecutive($person->role, $person->role_level)
                && ((int) $person->getKey() === (int) $actor->getKey() || (PortalRules::covers($reach, $actor, $person) && $listed($person))))
            ->map(static fn (Employee $person): int => (int) $person->getKey())
            ->values()
            ->all();
        $lists = PortalRules::holds($actor, $request, self::ALL_RULE.'.'.PortalRules::UNLISTED)
            ? []
            : PortalAccessGraph::forViewer($actor, $request)->listsOn((int) $actor->getKey(), self::ALL_RULE);

        return [
            'ids' => $ids,
            'unknownToo' => $reach === 'all' && $lists === [],
            'everyone' => $employees->map(static fn (Employee $person): int => (int) $person->getKey())->values()->all(),
        ];
    }

    private function applyType(Builder $query, ?string $type): void
    {
        if ($type === PortalRecycleBinPresenter::TYPE_REQUEST) {
            $query->where('resource', 'like', 'request%');

            return;
        }

        if ($type === PortalRecycleBinPresenter::TYPE_PROJECT) {
            $query->where('resource', 'like', 'project%');

            return;
        }

        if ($type === PortalRecycleBinPresenter::TYPE_REPORT) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('resource')
                    ->orWhere('resource', '')
                    ->orWhere('resource', 'like', 'report%');
            });
        }
    }

    private function applySearch(Builder $query, string $search, bool $canViewAll): void
    {
        if ($search === '') {
            return;
        }

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $nameIds = $canViewAll ? $this->employeeIdsMatching($word) : [];
            $query->where(function (Builder $builder) use ($like, $nameIds): void {
                $builder
                    ->where('record_id', 'like', $like)
                    ->orWhere('recycle_key', 'like', $like)
                    ->orWhere('resource', 'like', $like)
                    ->orWhere('deleted_by_id_no', 'like', $like)
                    ->orWhere('payload->title', 'like', $like)
                    ->orWhere('payload->kind', 'like', $like)
                    ->orWhere('payload->submittedOn', 'like', $like)
                    ->orWhere('payload->id', 'like', $like);
                if ($nameIds !== []) {
                    $builder->orWhereIn('deleted_by', $nameIds);
                }
            });
        }
    }

    private function applyOrder(Builder $query, string $sort, string $dir): void
    {
        if ($sort === '' || ! array_key_exists($sort, self::SORTABLE)) {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');

            return;
        }

        foreach (self::SORTABLE[$sort] as $column) {
            $query->orderBy($column, $dir);
        }
    }

    /**
     * @return list<int>
     */
    private function deletedByIds(array $visible): array
    {
        $query = Recycle::query()->where('product', CoreLedger::PRODUCT_PORTAL);
        PortalVisibleActors::apply($query, $visible, 'deleted_by');

        return $query
            ->toBase()
            ->whereNotNull('deleted_by')
            ->distinct()
            ->orderBy('deleted_by')
            ->pluck('deleted_by')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function employeeIdsMatching(string $word): array
    {
        $like = '%'.$word.'%';

        return Employee::query()
            ->toBase()
            ->select(['id'])
            ->where(function ($builder) use ($like): void {
                $builder
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('id_no', 'like', $like);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: string, idNo: ?string}>
     */
    private function employeeDirectory(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $directory = [];
        foreach (
            Employee::query()
                ->select(PortalRecycleBinPresenter::employeeColumns())
                ->whereIn('id', $ids)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get() as $employee
        ) {
            if (! $employee instanceof Employee) {
                continue;
            }
            $id = (int) $employee->getKey();
            $idNo = is_string($employee->id_no) && $employee->id_no !== '' ? $employee->id_no : null;
            $directory[$id] = [
                'name' => PortalSubmittedReportPresenter::memberName(
                    is_string($employee->first_name) ? $employee->first_name : null,
                    is_string($employee->last_name) ? $employee->last_name : null,
                ),
                'idNo' => $idNo,
            ];
        }

        return $directory;
    }

    private function scope(Request $request, bool $canViewAll): string
    {
        if (! $canViewAll) {
            return self::SCOPE_OWN;
        }

        $value = strtolower(trim((string) $request->query('scope', self::SCOPE_OWN)));

        return $value === self::SCOPE_ALL ? self::SCOPE_ALL : self::SCOPE_OWN;
    }

    private function employeeId(Request $request, bool $canViewAll, string $scope): ?int
    {
        if (! $canViewAll || $scope !== self::SCOPE_ALL) {
            return null;
        }

        $raw = $request->query('employee_id');
        if ($raw === null || $raw === '') {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function type(Request $request): ?string
    {
        $value = strtolower(trim((string) $request->query('type', '')));
        if (in_array($value, [
            PortalRecycleBinPresenter::TYPE_REPORT,
            PortalRecycleBinPresenter::TYPE_REQUEST,
            PortalRecycleBinPresenter::TYPE_PROJECT,
        ], true)) {
            return $value;
        }

        return null;
    }

    private function perPage(Request $request): int
    {
        $size = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($size, self::PAGE_SIZES, true) ? $size : self::DEFAULT_PAGE_SIZE;
    }
}
