<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
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

    private const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    private const RULE = 'manage.users';

    private const INFORMATION_RULE = 'manage.users.information';

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

    public function __construct(
        private readonly PortalAudit $audit,
        private readonly CoreLedger $ledger,
        private readonly PortalEmployeeSignature $signatures,
        private readonly PortalSignature $signature,
    ) {}

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
        $department = mb_substr(PortalPreview::department($request->query('department', '')), 0, 100);
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        $viewer = $request->attributes->get('portalEmployee');
        if (! $viewer instanceof Employee) {
            abort(401, 'Authentication is required.');
        }
        $viewerId = (int) $viewer->getKey();
        $live = PortalDeveloperMode::isOn($viewer, $request) ? $viewerId : null;
        $reach = PortalRules::reach($viewer, $request, self::RULE);

        // The viewer, their Developer mode and their reach are part of the key: who they see and may open differ.
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:manage:users:'.$version.':'.$viewerId.':'.($live === null ? 'd' : 'dev').':'.($reach ?? '-').':'.md5(PortalPreview::department($viewer->department))
            .':'.$page.':'.$perPage.':'.md5($search).':'.md5($department).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($viewer, $viewerId, $live, $reach, $perPage, $page, $search, $department, $sort, $dir): array {
            $query = $this->rosterQuery();
            $this->applyReach($query, $viewer, $reach);
            $this->applyDepartment($query, $department);
            $this->applySearch($query, $search);
            $this->applyOrder($query, $sort, $dir);

            $pageResult = $query
                ->paginate($perPage, PortalManageUserPresenter::rosterColumns(), 'page', $page);

            $graph = PortalAccessGraph::load($live);
            $rows = [];
            foreach ($pageResult->items() as $employee) {
                if ($employee instanceof Employee) {
                    $rows[] = [
                        ...PortalManageUserPresenter::rosterItem($employee),
                        'canEditAccess' => $graph->canManage($viewerId, (int) $employee->getKey()),
                    ];
                }
            }

            return [
                'section' => 'manage',
                'resource' => 'users',
                'data' => $rows,
                'counts' => $this->counts($department, $viewer, $reach),
                'departments' => $this->departments($viewer, $reach),
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

        // Someone outside the viewer's Users reach answers exactly like someone who does not exist.
        $inReach = $this->rosterQuery();
        $this->applyReach($inReach, $actor, PortalRules::reach($actor, $request, self::RULE));
        $target = $inReach->find($id);
        if (! $target instanceof Employee) {
            abort(404, 'Unknown member.');
        }

        $this->assertCanChange($request, $actor, $target, $role);

        if (PortalRole::normalize($target->role) !== PortalRole::normalize($role)) {
            // Captured before the mutating assignment below: $target->role is overwritten in
            // place, so the log's "what changed from" would otherwise already be gone.
            $previousRole = $target->role;
            $target->role = $role;
            // Same target, resource and payload shape as a grid edit of this column, so one
            // sync rule covers the role no matter which screen changed it.
            $this->ledger->within($target->getConnection(), function () use ($target, $actor, $previousRole, $role): void {
                $target->save();
                $this->ledger->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreLedger::PRODUCT_PORTAL.'.employees',
                    [
                        'table' => 'employees',
                        'key' => 'id',
                        'fields' => ['role' => $role],
                        'changes' => ['role' => ['from' => $previousRole, 'to' => $role]],
                    ],
                    'employees',
                    (string) $target->getKey(),
                    null,
                    (int) $actor->getKey(),
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
            });
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
                PortalActivityCopy::ownRoleChanged($actor, $role, $request),
                (string) $target->getKey(),
                $request,
                ['previousRole' => $previousRole, 'newRole' => $role],
            );
            $this->audit->notifyIfOther(
                $target,
                $actor,
                PortalNotificationType::ROLE_CHANGED,
                'Role changed',
                PortalActivityCopy::notifyRoleChanged($role, PortalActivityCopy::actorName($actor, $request)),
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
            'counts' => $this->counts('', $actor, PortalRules::reach($actor, $request, self::RULE)),
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
    public function counts(string $department = '', ?Employee $viewer = null, ?string $reach = 'all'): array
    {
        $query = Employee::query();
        if ($viewer !== null) {
            $this->applyReach($query, $viewer, $reach);
        }
        $this->applyDepartment($query, $department);
        $row = $query
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

    /**
     * One person's profile, when they are in the viewer's Users reach (404 otherwise, as if they did
     * not exist). Their private details come only with Show employee information reaching them, and
     * an executive's only to an executive or in Developer mode; each such look is logged.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function profile(Employee $viewer, int $id, Request $request): array
    {
        $target = $this->inReach($viewer, $id, $request);
        $withPrivate = $this->seesPrivate($viewer, $target, $request);
        if ($withPrivate && ! $target->is($viewer)) {
            $this->audit->record(
                $viewer,
                PortalLogAction::VIEW,
                self::INFORMATION_RULE,
                '{UserName|You} viewed the employee information of '.PortalAccessGraph::name($target),
                (string) $target->getKey(),
                $request,
                ['employeeId' => (int) $target->getKey()],
            );
        }

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($viewer, $target, $request, $withPrivate),
        ];
    }

    /**
     * Edit one person's signature profile (the portal's own copy). Their private details are not
     * touched; only the fields a signature shows. The Edit profiles power gates the route.
     *
     * @param  array<string, mixed>  $fields
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateProfile(Employee $actor, int $id, array $fields, Request $request): array
    {
        $target = $this->inReach($actor, $id, $request);
        $this->signatures->update($actor, $target, $fields, $request);
        $this->bumpCache();

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($actor, $target, $request, $this->seesPrivate($actor, $target, $request)),
        ];
    }

    // The person, when they are inside the viewer's Users reach; 404 otherwise, as if they did not exist.
    private function inReach(Employee $viewer, int $id, Request $request): Employee
    {
        $query = Employee::query();
        $this->applyReach($query, $viewer, PortalRules::reach($viewer, $request, self::RULE));
        $target = $query->find($id);
        if (! $target instanceof Employee) {
            abort(404, 'Unknown member.');
        }

        return $target;
    }

    private function seesPrivate(Employee $viewer, Employee $target, Request $request): bool
    {
        $seesExecutives = PortalRole::isExecutive($viewer->role, $viewer->role_level) || PortalDeveloperMode::isOn($viewer, $request);

        return PortalRules::covers(PortalRules::reach($viewer, $request, self::INFORMATION_RULE), $viewer, $target, $seesExecutives);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileData(Employee $viewer, Employee $target, Request $request, bool $withPrivate): array
    {
        return [
            ...PortalManageUserPresenter::profile($target, PortalEmployeePresenter::photoUrl($target), $withPrivate),
            // The signature block the portal curates, and whether this viewer may edit it.
            'canEditProfile' => PortalRules::holds($viewer, $request, PortalEmployeeSignature::RULE),
            'signature' => $this->signatures->values($target),
            // The signature footers (those carrying $SIGNATURE) an editor may assign.
            'signatureFooters' => $this->signature->footers(),
        ];
    }

    /**
     * Who the viewer's Users reach covers: everyone, their department, or only themselves. Executives
     * are listed like anyone (their role stays locked); only their private details are guarded.
     */
    private function applyReach(Builder $query, Employee $viewer, ?string $reach): void
    {
        $department = PortalPreview::department($viewer->department);
        match (true) {
            $reach === 'all' => null,
            $reach === 'department' && $department !== '' => $query->where(static fn (Builder $scoped): Builder => $scoped
                ->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [$department])
                ->orWhere('id', $viewer->getKey())),
            default => $query->where('id', $viewer->getKey()),
        };
    }

    // The figures and rows narrow together, so a department's tiles describe the rows under them.
    private function applyDepartment(Builder $query, string $department): void
    {
        if ($department !== '') {
            $query->whereRaw("LOWER(TRIM(COALESCE(department, ''))) = ?", [$department]);
        }
    }

    /**
     * Every department on the roster, for the filter; spelled as the roster mostly spells it.
     *
     * @return list<string>
     */
    private function departments(Employee $viewer, ?string $reach): array
    {
        $names = [];
        $query = Employee::query()->whereNotNull('department');
        $this->applyReach($query, $viewer, $reach);
        foreach ($query->pluck('department') as $value) {
            $name = trim((string) $value);
            if ($name !== '') {
                $names[PortalPreview::department($name)] ??= $name;
            }
        }
        natcasesort($names);

        return array_values($names);
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
            PortalDeveloperMode::isOn($actor, $request),
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
