<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Manage / Users roster: one skinny list query, one aggregate for the metric tiles.
 */
final class PortalManageUsers
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    private const CACHE_VERSION_KEY = 'portal:manage:users:version';

    /**
     * Table column key => SQL columns. Unknown keys fall back to the default rank order.
     *
     * @var array<string, list<string>|null>
     */
    private const SORTABLE = [
        'name' => ['last_name', 'first_name', 'id'],
        'email' => ['email', 'id'],
        'department' => ['department', 'last_name', 'id'],
        'title' => ['job_title', 'last_name', 'id'],
        'status' => ['status', 'last_name', 'id'],
        'access' => null,
    ];

    public function __construct(private readonly PortalAudit $audit) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{admins: int, members: int, active: int, inactive: int, total: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Request $request): array
    {
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:manage:users:'.$version.':'.$page.':'.$perPage.':'.md5($search).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($perPage, $page, $search, $sort, $dir): array {
            $query = $this->rosterQuery();
            $this->applySearch($query, $search);
            $this->applyOrder($query, $sort, $dir);

            $pageResult = $query
                ->paginate($perPage, PortalManageUserPresenter::rosterColumns(), 'page', $page);

            $rows = [];
            foreach ($pageResult->items() as $employee) {
                if ($employee instanceof Employee) {
                    $rows[] = PortalManageUserPresenter::rosterItem($employee);
                }
            }

            return [
                'section' => 'manage',
                'resource' => 'users',
                'data' => $rows,
                'counts' => $this->counts(),
                'meta' => [
                    'current_page' => $pageResult->currentPage(),
                    'per_page' => $pageResult->perPage(),
                    'total' => $pageResult->total(),
                    'last_page' => $pageResult->lastPage(),
                ],
            ];
        });
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array<string, mixed>,
     *     counts: array{admins: int, members: int, active: int, inactive: int, total: int}
     * }
     */
    public function updateRole(Employee $actor, int $id, string $role, Request $request): array
    {
        if (! in_array($role, PortalRole::assignableRoles(), true)) {
            abort(422, 'Role must be Admin, User, or ProjectAdmin.');
        }

        $target = $this->rosterQuery()->find($id);
        if (! $target instanceof Employee) {
            abort(404, 'Unknown member.');
        }

        $this->assertCanChange($request, $actor, $target, $role);

        if (PortalRole::normalize($target->role) !== PortalRole::normalize($role)) {
            // Captured before the mutating assignment below: $target->role is overwritten in
            // place, so the log's "what changed from" would otherwise already be gone.
            $previousRole = $target->role;
            $target->role = $role;
            $target->save();
            $this->bumpCache();
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                'manage.users',
                PortalActivityCopy::changedSomeoneRole($target, $role),
                (string) $target->getKey(),
                $request,
                ['previousRole' => $previousRole, 'newRole' => $role],
            );
            $this->audit->record(
                $target,
                PortalLogAction::PATCH,
                'manage.users',
                PortalActivityCopy::ownRoleChanged($actor, $role),
                (string) $target->getKey(),
                $request,
                ['previousRole' => $previousRole, 'newRole' => $role],
            );
            $this->audit->notifyIfOther(
                $target,
                $actor,
                PortalNotificationType::ROLE_CHANGED,
                'Role changed',
                PortalActivityCopy::notifyRoleChanged($role, PortalActivityCopy::displayName($actor)),
                PortalShellPath::USER_MANAGEMENT,
                'Open users',
                ['recordId' => (string) $target->getKey()],
            );
            $target = $this->rosterQuery()->findOrFail($id);
        }

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => PortalManageUserPresenter::rosterItem($target),
            'counts' => $this->counts(),
        ];
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * @return array{admins: int, members: int, active: int, inactive: int, total: int}
     */
    public function counts(): array
    {
        $row = Employee::query()
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(role_level, '')) = 'executive' OR LOWER(COALESCE(role, '')) = 'admin' THEN 1 ELSE 0 END) as admins")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(status, '')) = 'active' THEN 1 ELSE 0 END) as active")
            ->first();

        $total = (int) ($row->total ?? 0);
        $admins = (int) ($row->admins ?? 0);
        $active = (int) ($row->active ?? 0);

        return PortalManageUserPresenter::counts($admins, $active, $total);
    }

    public static function validatedRole(Request $request): string
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(PortalRole::assignableRoles())],
        ]);

        return $validated['role'];
    }

    private function rosterQuery(): Builder
    {
        return Employee::query()->select(PortalManageUserPresenter::rosterColumns());
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.$word.'%';
            $query->where(function (Builder $builder) use ($like): void {
                foreach (['first_name', 'last_name', 'email', 'id_no', 'department', 'job_title', 'nickname'] as $column) {
                    $builder->orWhere($column, 'like', $like);
                }
            });
        }
    }

    private function applyOrder(Builder $query, string $sort, string $dir): void
    {
        if ($sort === '' || ! array_key_exists($sort, self::SORTABLE)) {
            $this->applyDefaultOrder($query);

            return;
        }

        if ($sort === 'access') {
            $query->orderByRaw(
                "CASE WHEN LOWER(COALESCE(role_level, '')) = 'executive' THEN 0 WHEN LOWER(COALESCE(role, '')) = 'admin' THEN 1 WHEN LOWER(COALESCE(role, '')) = 'projectadmin' THEN 2 ELSE 3 END ".$dir
            );
            $query->orderBy('last_name', $dir)->orderBy('id', $dir);

            return;
        }

        foreach (self::SORTABLE[$sort] ?? [] as $column) {
            $query->orderBy($column, $dir);
        }
    }

    private function applyDefaultOrder(Builder $query): void
    {
        $query->orderByRaw("CASE WHEN LOWER(COALESCE(role_level, '')) = 'executive' THEN 0 WHEN LOWER(COALESCE(role, '')) = 'admin' THEN 1 ELSE 2 END")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    private function perPage(Request $request): int
    {
        $value = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($value, self::PAGE_SIZES, true) ? $value : self::DEFAULT_PAGE_SIZE;
    }

    private function assertCanChange(Request $request, Employee $actor, Employee $target, string $newRole): void
    {
        if (PortalRole::isLocked($target->role, $target->role_level)) {
            PortalAccessDenied::abort($request, 'The executive role cannot be changed.');
        }

        $block = PortalRole::roleChangeBlock(
            $actor->getKey(),
            $target->getKey(),
            false,
            PortalRole::isAdmin($target->role, $target->role_level),
            PortalRole::isRemovingAdminRights($target->role, $target->role_level, $newRole),
            $this->adminCount(),
        );
        if ($block !== null) {
            abort(403, $block);
        }
    }

    private function adminCount(): int
    {
        return (int) Employee::query()
            ->where(function (Builder $builder): void {
                $builder->whereRaw("LOWER(COALESCE(role_level, '')) = 'executive'")
                    ->orWhereRaw("LOWER(COALESCE(role, '')) = 'admin'");
            })
            ->count();
    }
}
