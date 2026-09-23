<?php

namespace App\Support\Portal;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Administration / All Actions: the core write-action ledger, scoped to the portal product.
 *
 * Distinct from `PortalUserLogs`, which reads the portal's own activity log -- that one answers
 * "who looked at what", this one answers "what did a write actually change".
 *
 * The rows live on the core connection while the actor names live on the portal connection, so a
 * name is never joined: ids are collected from the page and the facets, then resolved in one
 * lookup, the same way `PortalRecycleBin` does it.
 */
final class PortalAllActions
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    private const CACHE_VERSION_KEY = 'portal:administration:all-actions:version';

    /**
     * Table column key => SQL columns. Unknown keys fall back to newest first.
     *
     * `employee` sorts by id number rather than name: names are on the other connection, the same
     * compromise `PortalRecycleBin::SORTABLE` already makes.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE = [
        'date' => ['created_at', 'id'],
        'action' => ['action_type', 'created_at', 'id'],
        'resource' => ['resource', 'created_at', 'id'],
        'record' => ['record_id', 'created_at', 'id'],
        'target' => ['database_target', 'created_at', 'id'],
        'employee' => ['actor_id_no', 'actor_id', 'id'],
        'synced' => ['synced_at', 'created_at', 'id'],
    ];

    /**
     * Resolved per call, never captured: a Route memoizes the controller it built, so a zone held
     * in a constructor would answer for whoever happened to call first.
     */
    public function __construct(private readonly PortalTimezone $timezone) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actionTypes: list<string>,
     *         resources: list<string>,
     *         databaseTargets: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>,
     *         actors: list<array{value: string, label: string}>
     *     },
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function listAll(Request $request): array
    {
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $actionType = $this->actionType($request);
        $resource = $this->resource($request);
        $databaseTarget = $this->databaseTarget($request);
        $actorId = $this->actorId($request);
        $year = $this->year($request);
        $month = $this->month($request);
        $day = $this->day($request);
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $timezone = $this->timezone->zone();
        $timezoneKey = md5($timezone->getName());
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:all-actions:'.$version.':'.$timezoneKey.':'.$page.':'.$perPage
            .':'.md5($search).':'.($actionType ?? 'all').':'.md5($resource ?? 'all')
            .':'.md5($databaseTarget ?? 'all').':'.($actorId ?? 'all')
            .':'.($year ?? 'all').':'.($month ?? 'all').':'.($day ?? 'all').':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $actionType,
            $actorId,
            $databaseTarget,
            $day,
            $dir,
            $month,
            $page,
            $perPage,
            $resource,
            $search,
            $sort,
            $timezone,
            $year,
        ): array {
            return $this->buildAll(
                $actionType,
                $resource,
                $databaseTarget,
                $actorId,
                $year,
                $month,
                $day,
                $dir,
                $page,
                $perPage,
                $search,
                $sort,
                $timezone,
            );
        });
    }

    /**
     * One ledger row, payload included. `AllActionsController` is already Admin/Executive only, so
     * no further ownership check happens here.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function showAll(int $id): array
    {
        $timezone = $this->timezone->zone();
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:all-actions:detail:'.$version.':'.md5($timezone->getName()).':'.$id;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($id, $timezone): array {
            $row = Action::query()
                ->select(PortalAllActionsPresenter::detailColumns())
                ->where('product', CoreLedger::PRODUCT_PORTAL)
                ->where('id', $id)
                ->first();
            if (! $row instanceof Action) {
                abort(404, 'That action was not found.');
            }

            $employeeId = is_numeric($row->actor_id) ? (int) $row->actor_id : 0;

            return [
                'section' => 'administration',
                'resource' => 'all-actions',
                'data' => PortalAllActionsPresenter::detail(
                    $row,
                    $this->employeeDirectory($employeeId > 0 ? [$employeeId] : []),
                    $timezone,
                ),
            ];
        });
    }

    /**
     * Declared for symmetry with the other administration reads. Nothing calls it today: the
     * ledger is append-only from `CoreLedger`, which runs inside a shared core transaction serving
     * every product, so bumping from there would couple that write path to this one read model.
     * The TTL above is the whole staleness window.
     */
    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actionTypes: list<string>,
     *         resources: list<string>,
     *         databaseTargets: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>,
     *         actors: list<array{value: string, label: string}>
     *     },
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function buildAll(
        ?string $actionType,
        ?string $resource,
        ?string $databaseTarget,
        ?int $actorId,
        ?string $year,
        ?string $month,
        ?string $day,
        string $dir,
        int $page,
        int $perPage,
        string $search,
        string $sort,
        DateTimeZone $timezone,
    ): array {
        $columns = PortalAllActionsPresenter::columns();
        $query = Action::query()
            ->select($columns)
            ->where('product', CoreLedger::PRODUCT_PORTAL);
        $total = (int) (clone $query)->toBase()->count();
        $filters = $this->filters($timezone);

        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }
        if ($actionType !== null) {
            $query->where('action_type', $actionType);
        }
        if ($resource !== null) {
            $query->where('resource', $resource);
        }
        if ($databaseTarget !== null) {
            $query->where('database_target', $databaseTarget);
        }
        $this->applyDate($query, $year, $month, $day, $timezone, $filters['years']);
        $this->applySearch($query, $search);
        $this->applyOrder($query, $sort, $dir);

        $pageResult = $query->paginate($perPage, $columns, 'page', $page);

        $ids = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof Action && is_numeric($row->actor_id) && (int) $row->actor_id > 0) {
                $ids[(int) $row->actor_id] = true;
            }
        }
        foreach ($filters['actors'] as $actor) {
            $ids[(int) $actor['value']] = true;
        }
        $employees = $this->employeeDirectory(array_keys($ids));

        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof Action) {
                $rows[] = PortalAllActionsPresenter::item($row, $employees, $timezone);
            }
        }

        return [
            'section' => 'administration',
            'resource' => 'all-actions',
            'data' => $rows,
            'filters' => $this->labelActors($filters, $employees),
            'counts' => ['total' => $total],
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    /**
     * Facets come from the whole portal-scoped ledger, not the current page. Actor options leave
     * here carrying their id as the label -- `labelActors` swaps in real names once the one
     * cross-connection lookup has run.
     *
     * @return array{
     *     actionTypes: list<string>,
     *     resources: list<string>,
     *     databaseTargets: list<string>,
     *     years: list<string>,
     *     months: list<array{value: string, label: string}>,
     *     days: list<array{value: string, label: string}>,
     *     actors: list<array{value: string, label: string}>
     * }
     */
    private function filters(DateTimeZone $timezone): array
    {
        // toBase() drops the model casts, so created_at arrives as a string here.
        $rows = Action::query()
            ->select(['action_type', 'resource', 'database_target', 'actor_id', 'actor_id_no', 'created_at'])
            ->where('product', CoreLedger::PRODUCT_PORTAL)
            ->toBase()
            ->get();

        $actionTypes = [];
        $resources = [];
        $databaseTargets = [];
        $years = [];
        $months = [];
        $days = [];
        $actors = [];

        foreach ($rows as $row) {
            $actionType = strtolower(trim((string) ($row->action_type ?? '')));
            if ($actionType !== '') {
                $actionTypes[$actionType] = true;
            }
            $resource = trim((string) ($row->resource ?? ''));
            if ($resource !== '') {
                $resources[$resource] = true;
            }
            $databaseTarget = trim((string) ($row->database_target ?? ''));
            if ($databaseTarget !== '') {
                $databaseTargets[$databaseTarget] = true;
            }
            $actorId = (int) ($row->actor_id ?? 0);
            if ($actorId > 0 && ! isset($actors[$actorId])) {
                $idNo = trim((string) ($row->actor_id_no ?? ''));
                $actors[$actorId] = $idNo !== '' ? $idNo : (string) $actorId;
            }
            if ($row->created_at === null || $row->created_at === '') {
                continue;
            }
            $local = Carbon::parse((string) $row->created_at)->setTimezone($timezone);
            $years[$local->format('Y')] = true;
            $months[$local->format('m')] = true;
            $days[$local->format('Y-m-d')] = true;
        }

        ksort($actionTypes);
        ksort($resources);
        ksort($databaseTargets);

        $yearList = array_map(static fn (int|string $value): string => (string) $value, array_keys($years));
        rsort($yearList, SORT_STRING);

        $monthList = [];
        foreach (array_keys($months) as $value) {
            $monthList[] = str_pad((string) $value, 2, '0', STR_PAD_LEFT);
        }
        sort($monthList, SORT_STRING);

        $dayList = array_keys($days);
        rsort($dayList, SORT_STRING);

        $monthOptions = [];
        foreach ($monthList as $value) {
            $stamp = Carbon::createFromFormat('!m', $value, $timezone);
            $monthOptions[] = [
                'value' => $value,
                'label' => $stamp === false ? $value : $stamp->format('F'),
            ];
        }

        $dayOptions = [];
        foreach ($dayList as $value) {
            $stamp = Carbon::createFromFormat('Y-m-d', $value, $timezone);
            $dayOptions[] = [
                'value' => $value,
                'label' => $stamp === false ? $value : $stamp->format('F j, Y'),
            ];
        }

        $actorOptions = [];
        ksort($actors);
        foreach ($actors as $id => $label) {
            $actorOptions[] = [
                'value' => (string) $id,
                'label' => $label,
            ];
        }

        return [
            'actionTypes' => array_keys($actionTypes),
            'resources' => array_keys($resources),
            'databaseTargets' => array_keys($databaseTargets),
            'years' => $yearList,
            'months' => $monthOptions,
            'days' => $dayOptions,
            'actors' => $actorOptions,
        ];
    }

    /**
     * @param  array{actionTypes: list<string>, resources: list<string>, databaseTargets: list<string>, years: list<string>, months: list<array{value: string, label: string}>, days: list<array{value: string, label: string}>, actors: list<array{value: string, label: string}>}  $filters
     * @param  array<int, array{name: string, idNo: ?string}>  $employees
     * @return array{actionTypes: list<string>, resources: list<string>, databaseTargets: list<string>, years: list<string>, months: list<array{value: string, label: string}>, days: list<array{value: string, label: string}>, actors: list<array{value: string, label: string}>}
     */
    private function labelActors(array $filters, array $employees): array
    {
        $actors = [];
        foreach ($filters['actors'] as $actor) {
            $id = (int) $actor['value'];
            $actors[] = [
                'value' => $actor['value'],
                'label' => $employees[$id]['name'] ?? $actor['label'],
            ];
        }
        usort($actors, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        $filters['actors'] = $actors;

        return $filters;
    }

    /**
     * Names live on the portal connection while the ledger lives on core, so this is a second
     * lookup rather than a join.
     *
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
                ->select(PortalAllActionsPresenter::employeeColumns())
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

    /**
     * @return list<int>
     */
    private function employeeIdsMatching(string $word): array
    {
        $like = '%'.$word.'%';
        $ids = Employee::query()
            ->select('id')
            ->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('id_no', 'like', $like);
            })
            ->pluck('id')
            ->all();

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    /**
     * The `parameters` blob is deliberately not searched: it is the one unindexed column, and every
     * field worth matching on is already duplicated into a column beside it.
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $matchingIds = $this->employeeIdsMatching($word);
            $query->where(function (Builder $builder) use ($like, $matchingIds): void {
                $builder
                    ->where('action_type', 'like', $like)
                    ->orWhere('resource', 'like', $like)
                    ->orWhere('record_id', 'like', $like)
                    ->orWhere('recycle_key', 'like', $like)
                    ->orWhere('database_target', 'like', $like)
                    ->orWhere('actor_id_no', 'like', $like);
                if ($matchingIds !== []) {
                    $builder->orWhereIn('actor_id', $matchingIds);
                }
            });
        }
    }

    /**
     * @param  list<string>  $years
     */
    private function applyDate(
        Builder $query,
        ?string $year,
        ?string $month,
        ?string $day,
        DateTimeZone $timezone,
        array $years,
    ): void {
        if ($day !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $day, $timezone);
            if ($start === false) {
                return;
            }
            $start = $start->startOfDay();
            $query->whereBetween('created_at', [
                $start->copy()->utc()->toDateTimeString(),
                $start->copy()->endOfDay()->utc()->toDateTimeString(),
            ]);

            return;
        }

        if ($year !== null && $month !== null) {
            $this->constrainToMonths($query, [$year], $month, $timezone);

            return;
        }

        if ($year !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $year.'-01-01', $timezone);
            if ($start === false) {
                return;
            }
            $start = $start->startOfYear();
            $query->whereBetween('created_at', [
                $start->copy()->utc()->toDateTimeString(),
                $start->copy()->endOfYear()->utc()->toDateTimeString(),
            ]);

            return;
        }

        if ($month !== null) {
            $this->constrainToMonths($query, $years, $month, $timezone);
        }
    }

    /**
     * @param  list<string>  $years
     */
    private function constrainToMonths(Builder $query, array $years, string $month, DateTimeZone $timezone): void
    {
        if ($years === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $builder) use ($years, $month, $timezone): void {
            foreach ($years as $oneYear) {
                $start = Carbon::createFromFormat('Y-m-d', $oneYear.'-'.$month.'-01', $timezone);
                if ($start === false) {
                    continue;
                }
                $start = $start->startOfMonth();
                $builder->orWhereBetween('created_at', [
                    $start->copy()->utc()->toDateTimeString(),
                    $start->copy()->endOfMonth()->utc()->toDateTimeString(),
                ]);
            }
        });
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

    private function actionType(Request $request): ?string
    {
        $value = strtolower(trim((string) $request->query('action_type', '')));
        if ($value !== '' && preg_match('/^[a-z-]{1,32}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function resource(Request $request): ?string
    {
        $value = strtolower(trim((string) $request->query('resource', '')));
        if ($value !== '' && preg_match('/^[a-z0-9._-]{1,80}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function databaseTarget(Request $request): ?string
    {
        $value = strtolower(trim((string) $request->query('database_target', '')));
        if ($value !== '' && preg_match('/^[a-z0-9._-]{1,191}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function actorId(Request $request): ?int
    {
        $value = $request->integer('actor_id', 0);

        return $value > 0 ? $value : null;
    }

    private function year(Request $request): ?string
    {
        $value = trim((string) $request->query('year', ''));
        if (preg_match('/^\d{4}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    private function month(Request $request): ?string
    {
        $value = trim((string) $request->query('month', ''));
        if (preg_match('/^(0?[1-9]|1[0-2])$/', $value) === 1) {
            return str_pad($value, 2, '0', STR_PAD_LEFT);
        }

        return null;
    }

    private function day(Request $request): ?string
    {
        $value = trim((string) $request->query('day', ''));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value) !== 1) {
            return null;
        }
        $parts = explode('-', $value);
        $year = (int) ($parts[0] ?? 0);
        $month = (int) ($parts[1] ?? 0);
        $day = (int) ($parts[2] ?? 0);
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return $value;
    }

    private function perPage(Request $request): int
    {
        $value = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($value, self::PAGE_SIZES, true) ? $value : self::DEFAULT_PAGE_SIZE;
    }
}
