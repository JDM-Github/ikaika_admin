<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * User / Logs: the signed-in member's own audit stream.
 */
final class PortalUserLogs
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * Table column key => SQL columns. Unknown keys fall back to newest first.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE = [
        'date' => ['created_at', 'id'],
        'action' => ['action', 'created_at', 'id'],
        'resource' => ['resource', 'created_at', 'id'],
        'employee' => ['last_name', 'first_name', 'created_at', 'id'],
    ];

    public function __construct(private readonly PortalTimezone $timezone) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actions: list<string>,
     *         resources: list<string>,
     *         ipAddresses: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>
     *     },
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Employee $actor, Request $request): array
    {
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $action = $this->action($request);
        $resource = $this->resource($request);
        $ipAddress = $this->ipAddress($request);
        $year = $this->year($request);
        $month = $this->month($request);
        $day = $this->day($request);
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $timezone = $this->timezone->zone();
        $timezoneKey = md5($timezone->getName());
        $version = (int) Cache::get(PortalAudit::LOG_CACHE_VERSION_KEY, 1);
        $key = 'portal:user:logs:'.$version.':'.$actor->getKey().':'.$timezoneKey.':'.$page.':'.$perPage
            .':'.md5($search).':'.($action ?? 'all').':'.($resource ?? 'all').':'.md5($ipAddress ?? 'all')
            .':'.($year ?? 'all').':'.($month ?? 'all').':'.($day ?? 'all').':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $actor,
            $action,
            $day,
            $dir,
            $ipAddress,
            $month,
            $page,
            $perPage,
            $resource,
            $search,
            $sort,
            $timezone,
            $year,
        ): array {
            return $this->build(
                $actor,
                $action,
                $resource,
                $ipAddress,
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
     * Administration / All Logs: every member's stream. Admin or Executive only.
     *
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actions: list<string>,
     *         resources: list<string>,
     *         ipAddresses: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>,
     *         users: list<array{value: string, label: string}>
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
        $action = $this->action($request);
        $resource = $this->resource($request);
        $ipAddress = $this->ipAddress($request);
        $year = $this->year($request);
        $month = $this->month($request);
        $day = $this->day($request);
        $employeeId = $this->employeeId($request);
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $timezone = $this->timezone->zone();
        $timezoneKey = md5($timezone->getName());
        $version = (int) Cache::get(PortalAudit::LOG_CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:all-logs:'.$version.':'.$timezoneKey.':'.$page.':'.$perPage
            .':'.md5($search).':'.($action ?? 'all').':'.($resource ?? 'all').':'.md5($ipAddress ?? 'all')
            .':'.($year ?? 'all').':'.($month ?? 'all').':'.($day ?? 'all').':'.($employeeId ?? 'all')
            .':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $action,
            $day,
            $dir,
            $employeeId,
            $ipAddress,
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
                $action,
                $resource,
                $ipAddress,
                $year,
                $month,
                $day,
                $employeeId,
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
     * One of the actor's own log rows, payload included. Someone else's id is 404 -- a member
     * reads their own stream only, the User / Logs rule List already enforces at the query level.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(Employee $actor, int $id): array
    {
        $employeeId = (int) $actor->getKey();
        $version = (int) Cache::get(PortalAudit::LOG_CACHE_VERSION_KEY, 1);
        $key = 'portal:user:logs:detail:'.$version.':'.$employeeId.':'.$id;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($employeeId, $id): array {
            $row = PortalLog::query()
                ->where('id', $id)
                ->where('employee_id', $employeeId)
                ->first();
            if (! $row instanceof PortalLog) {
                abort(404, 'That log entry was not found.');
            }

            return [
                'section' => 'user',
                'resource' => 'logs',
                'data' => PortalUserLogPresenter::detail($row, $this->timezone->zone()),
            ];
        });
    }

    /**
     * Any portal log row, payload included. `AllLogsController` is already Admin/Executive only,
     * so no further ownership check happens here.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function showAll(int $id): array
    {
        $version = (int) Cache::get(PortalAudit::LOG_CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:all-logs:detail:'.$version.':'.$id;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($id): array {
            $row = PortalLog::query()
                ->select(array_map(static fn (string $column): string => 'logs.'.$column, PortalUserLogPresenter::columns()))
                ->addSelect(['employees.first_name', 'employees.last_name', 'logs.payload'])
                ->leftJoin('employees', 'employees.id', '=', 'logs.employee_id')
                ->where('logs.id', $id)
                ->first();
            if (! $row instanceof PortalLog) {
                abort(404, 'That log entry was not found.');
            }

            return [
                'section' => 'administration',
                'resource' => 'all-logs',
                'data' => PortalUserLogPresenter::detail($row, $this->timezone->zone()),
            ];
        });
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actions: list<string>,
     *         resources: list<string>,
     *         ipAddresses: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>,
     *         users: list<array{value: string, label: string}>
     *     },
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function buildAll(
        ?string $action,
        ?string $resource,
        ?string $ipAddress,
        ?string $year,
        ?string $month,
        ?string $day,
        ?int $employeeId,
        string $dir,
        int $page,
        int $perPage,
        string $search,
        string $sort,
        DateTimeZone $timezone,
    ): array {
        $columns = array_map(
            static fn (string $column): string => 'logs.'.$column,
            PortalUserLogPresenter::columns(),
        );
        $query = PortalLog::query()
            ->select(array_merge($columns, ['employees.first_name', 'employees.last_name']))
            ->leftJoin('employees', 'employees.id', '=', 'logs.employee_id');
        $total = (int) (clone $query)->toBase()->count();
        $filters = $this->filters(null, $timezone, true);

        if ($employeeId !== null) {
            $query->where('logs.employee_id', $employeeId);
        }
        if ($action !== null) {
            $query->where('logs.action', $action);
        }
        if ($resource !== null) {
            $query->where('logs.resource', $resource);
        }
        if ($ipAddress !== null) {
            $query->where('logs.ip_address', $ipAddress);
        }
        $this->applyDate($query, $year, $month, $day, $timezone, $filters['years'], 'logs.created_at');
        $this->applySearch($query, $search, true);
        $this->applyOrder($query, $sort, $dir, true);

        $pageResult = $query->paginate(
            $perPage,
            array_merge($columns, ['employees.first_name', 'employees.last_name']),
            'page',
            $page,
        );
        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof PortalLog) {
                $rows[] = PortalUserLogPresenter::item($row, $timezone);
            }
        }

        return [
            'section' => 'administration',
            'resource' => 'all-logs',
            'data' => $rows,
            'filters' => $filters,
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
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     filters: array{
     *         actions: list<string>,
     *         resources: list<string>,
     *         ipAddresses: list<string>,
     *         years: list<string>,
     *         months: list<array{value: string, label: string}>,
     *         days: list<array{value: string, label: string}>,
     *         users: list<array{value: string, label: string}>
     *     },
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function build(
        Employee $actor,
        ?string $action,
        ?string $resource,
        ?string $ipAddress,
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
        $employeeId = (int) $actor->getKey();
        $query = PortalLog::query()
            ->select(PortalUserLogPresenter::columns())
            ->where('employee_id', $employeeId);
        $total = (int) (clone $query)->toBase()->count();
        $filters = $this->filters($employeeId, $timezone);

        if ($action !== null) {
            $query->where('action', $action);
        }
        if ($resource !== null) {
            $query->where('resource', $resource);
        }
        if ($ipAddress !== null) {
            $query->where('ip_address', $ipAddress);
        }
        $this->applyDate($query, $year, $month, $day, $timezone, $filters['years']);
        $this->applySearch($query, $search);
        $this->applyOrder($query, $sort, $dir);

        $pageResult = $query->paginate($perPage, PortalUserLogPresenter::columns(), 'page', $page);
        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof PortalLog) {
                $rows[] = PortalUserLogPresenter::item($row, $timezone);
            }
        }

        return [
            'section' => 'user',
            'resource' => 'logs',
            'data' => $rows,
            'filters' => $filters,
            'counts' => ['total' => $total],
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    private function applySearch(Builder $query, string $search, bool $includeNames = false): void
    {
        if ($search === '') {
            return;
        }

        $prefix = $includeNames ? 'logs.' : '';

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $builder) use ($like, $prefix, $includeNames): void {
                $builder
                    ->where($prefix.'action', 'like', $like)
                    ->orWhere($prefix.'resource', 'like', $like)
                    ->orWhere($prefix.'record_id', 'like', $like)
                    ->orWhere($prefix.'message', 'like', $like)
                    ->orWhere($prefix.'ip_address', 'like', $like);
                if ($includeNames) {
                    $builder
                        ->orWhere('employees.first_name', 'like', $like)
                        ->orWhere('employees.last_name', 'like', $like);
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
        string $createdAt = 'created_at',
    ): void {
        if ($day !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $day, $timezone);
            if ($start === false) {
                return;
            }
            $start = $start->startOfDay();
            $query->whereBetween($createdAt, [
                $start->copy()->utc()->toDateTimeString(),
                $start->copy()->endOfDay()->utc()->toDateTimeString(),
            ]);

            return;
        }

        if ($year !== null && $month !== null) {
            $this->constrainToMonths($query, [$year], $month, $timezone, $createdAt);

            return;
        }

        if ($year !== null) {
            $start = Carbon::createFromFormat('Y-m-d', $year.'-01-01', $timezone);
            if ($start === false) {
                return;
            }
            $start = $start->startOfYear();
            $query->whereBetween($createdAt, [
                $start->copy()->utc()->toDateTimeString(),
                $start->copy()->endOfYear()->utc()->toDateTimeString(),
            ]);

            return;
        }

        if ($month !== null) {
            $this->constrainToMonths($query, $years, $month, $timezone, $createdAt);
        }
    }

    /**
     * @param  list<string>  $years
     */
    private function constrainToMonths(
        Builder $query,
        array $years,
        string $month,
        DateTimeZone $timezone,
        string $createdAt = 'created_at',
    ): void {
        if ($years === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $builder) use ($years, $month, $timezone, $createdAt): void {
            foreach ($years as $oneYear) {
                $start = Carbon::createFromFormat('Y-m-d', $oneYear.'-'.$month.'-01', $timezone);
                if ($start === false) {
                    continue;
                }
                $start = $start->startOfMonth();
                $builder->orWhereBetween($createdAt, [
                    $start->copy()->utc()->toDateTimeString(),
                    $start->copy()->endOfMonth()->utc()->toDateTimeString(),
                ]);
            }
        });
    }

    /**
     * Facets come from the scoped log set, not the current page.
     *
     * @return array{
     *     actions: list<string>,
     *     resources: list<string>,
     *     ipAddresses: list<string>,
     *     years: list<string>,
     *     months: list<array{value: string, label: string}>,
     *     days: list<array{value: string, label: string}>,
     *     users: list<array{value: string, label: string}>
     * }
     */
    private function filters(?int $employeeId, DateTimeZone $timezone, bool $includeUsers = false): array
    {
        $query = PortalLog::query();
        if ($includeUsers) {
            $query->select([
                'logs.action',
                'logs.resource',
                'logs.ip_address',
                'logs.created_at',
                'logs.employee_id',
                'employees.first_name',
                'employees.last_name',
            ])->leftJoin('employees', 'employees.id', '=', 'logs.employee_id');
        } else {
            $query->select(['action', 'resource', 'ip_address', 'created_at']);
        }
        if ($employeeId !== null) {
            $query->where($includeUsers ? 'logs.employee_id' : 'employee_id', $employeeId);
        }

        $rows = $query->toBase()->get();

        $actions = [];
        $resources = [];
        $ipAddresses = [];
        $years = [];
        $months = [];
        $days = [];
        $users = [];

        foreach ($rows as $row) {
            $action = trim((string) ($row->action ?? ''));
            if ($action !== '') {
                $actions[$action] = true;
            }
            $resource = trim((string) ($row->resource ?? ''));
            if ($resource !== '') {
                $resources[$resource] = true;
            }
            $ipAddress = trim((string) ($row->ip_address ?? ''));
            if ($ipAddress !== '') {
                $ipAddresses[$ipAddress] = true;
            }
            if ($includeUsers) {
                $id = (int) ($row->employee_id ?? 0);
                if ($id > 0 && ! isset($users[$id])) {
                    $users[$id] = PortalSubmittedReportPresenter::memberName(
                        is_string($row->first_name ?? null) ? $row->first_name : null,
                        is_string($row->last_name ?? null) ? $row->last_name : null,
                    );
                }
            }
            if ($row->created_at === null || $row->created_at === '') {
                continue;
            }
            $local = Carbon::parse((string) $row->created_at)->setTimezone($timezone);
            $years[$local->format('Y')] = true;
            $months[$local->format('m')] = true;
            $days[$local->format('Y-m-d')] = true;
        }

        ksort($actions);
        ksort($resources);
        ksort($ipAddresses);

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

        $userOptions = [];
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($users as $id => $label) {
            $userOptions[] = [
                'value' => (string) $id,
                'label' => $label,
            ];
        }

        return [
            'actions' => array_keys($actions),
            'resources' => array_keys($resources),
            'ipAddresses' => array_keys($ipAddresses),
            'years' => $yearList,
            'months' => $monthOptions,
            'days' => $dayOptions,
            'users' => $userOptions,
        ];
    }

    private function applyOrder(Builder $query, string $sort, string $dir, bool $joined = false): void
    {
        $createdAt = $joined ? 'logs.created_at' : 'created_at';
        $id = $joined ? 'logs.id' : 'id';
        if ($sort === '' || ! array_key_exists($sort, self::SORTABLE) || ($sort === 'employee' && ! $joined)) {
            $query->orderBy($createdAt, 'desc')->orderBy($id, 'desc');

            return;
        }

        foreach (self::SORTABLE[$sort] as $column) {
            if ($joined && ($column === 'last_name' || $column === 'first_name')) {
                $query->orderBy('employees.'.$column, $dir);

                continue;
            }
            $query->orderBy($joined ? 'logs.'.$column : $column, $dir);
        }
    }

    private function action(Request $request): ?string
    {
        $value = strtoupper(trim((string) $request->query('action', '')));
        if (in_array($value, [
            PortalLogAction::INSERT,
            PortalLogAction::PATCH,
            PortalLogAction::DELETE,
            PortalLogAction::POST,
        ], true)) {
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

    private function ipAddress(Request $request): ?string
    {
        $value = trim((string) $request->query('ip', ''));
        if ($value !== '' && strlen($value) <= 45 && preg_match('/^[0-9a-fA-F.:]+$/', $value) === 1) {
            return $value;
        }

        return null;
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

    private function employeeId(Request $request): ?int
    {
        $value = $request->integer('employee_id', 0);

        return $value > 0 ? $value : null;
    }

    private function perPage(Request $request): int
    {
        $value = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($value, self::PAGE_SIZES, true) ? $value : self::DEFAULT_PAGE_SIZE;
    }
}
