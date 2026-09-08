<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
    ];

    public function __construct(private readonly PortalTimezone $timezone) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
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
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';
        $timezone = $this->timezone->zone();
        $timezoneKey = md5($timezone->getName());
        $version = (int) Cache::get(PortalAudit::LOG_CACHE_VERSION_KEY, 1);
        $key = 'portal:user:logs:'.$version.':'.$actor->getKey().':'.$timezoneKey.':'.$page.':'.$perPage
            .':'.md5($search).':'.($action ?? 'all').':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $actor,
            $action,
            $dir,
            $page,
            $perPage,
            $search,
            $sort,
            $timezone,
        ): array {
            return $this->build($actor, $action, $dir, $page, $perPage, $search, $sort, $timezone);
        });
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function build(
        Employee $actor,
        ?string $action,
        string $dir,
        int $page,
        int $perPage,
        string $search,
        string $sort,
        DateTimeZone $timezone,
    ): array {
        $query = PortalLog::query()
            ->select(PortalUserLogPresenter::columns())
            ->where('employee_id', (int) $actor->getKey());
        $total = (int) (clone $query)->toBase()->count();

        if ($action !== null) {
            $query->where('action', $action);
        }
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
            'counts' => ['total' => $total],
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->where('action', 'like', $like)
                    ->orWhere('resource', 'like', $like)
                    ->orWhere('record_id', 'like', $like)
                    ->orWhere('message', 'like', $like);
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

    private function perPage(Request $request): int
    {
        $value = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($value, self::PAGE_SIZES, true) ? $value : self::DEFAULT_PAGE_SIZE;
    }
}
