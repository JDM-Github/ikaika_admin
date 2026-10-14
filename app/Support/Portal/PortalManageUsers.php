<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    private const ADD_RULE = 'manage.users.add';

    private const DEPARTMENT_RULE = 'manage.users.department';

    // Activate and deactivate (2026-10-12): an account's status, which sign-in and every session call check.
    private const STATUS_RULE = 'manage.users.status';

    private const PEERS_RULE = 'manage.users.access.peers';

    private const STATUSES = ['Active', 'Inactive'];

    // Edit employee information (2026-10-12): the private details, held by those who may see them.
    private const INFORMATION_EDIT_RULE = 'manage.users.information.edit';

    /** The private details it changes, by the key the profile sends them under. */
    private const INFORMATION_COLUMNS = [
        'dateOfBirth' => 'date_of_birth',
        'bloodType' => 'blood_type',
        'address' => 'address',
        'personalEmail' => 'personal_email',
        'phoneNumber' => 'phone_number',
        'taxIdentificationNo' => 'tax_identification_no',
        'sssNo' => 'sss_no',
        'philhealthNo' => 'philhealth_no',
        'hdmfNo' => 'hdmf_no',
        'bankName' => 'bank_name',
        'bankAccountNumber' => 'bank_account_number',
        'bankSwiftCode' => 'bank_swift_code',
        'emergencyContactName' => 'emergency_contact_person_name',
        'emergencyContactNumber' => 'emergency_contact_number',
        'emergencyContactAddress' => 'emergency_contact_address',
    ];

    /** The registration's name for a key the profile spells differently, so its cleaning applies. */
    private const REGISTRATION_KEYS = ['emergencyContactName' => 'emergencyContactPersonName'];

    // An ID No. is the start date and a running number: 260701-0019.
    private const ID_NO = '/^\d{6}-\d{4}$/';

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
        private readonly PortalRegistrationInput $input,
        private readonly PortalEmployeeDocuments $employeeDocuments,
        private readonly PortalEmployeeIdCards $idCards,
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
        $holdsStatus = PortalRules::holds($viewer, $request, self::STATUS_RULE);
        $holdsPeers = PortalRules::holds($viewer, $request, self::PEERS_RULE);

        // The viewer, their Developer mode, their reach and status powers are part of the key: who they see and may change differ.
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:manage:users:'.$version.':'.$viewerId.':'.($live === null ? 'd' : 'dev').':'.($reach ?? '-').':'.md5(PortalPreview::department($viewer->department))
            .':'.(int) $holdsStatus.(int) $holdsPeers.':'.$page.':'.$perPage.':'.md5($search).':'.md5($department).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($viewer, $viewerId, $live, $reach, $holdsStatus, $holdsPeers, $perPage, $page, $search, $department, $sort, $dir): array {
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
                        'canChangeStatus' => $holdsStatus && $this->statusRefusal($viewerId, $employee, $holdsPeers) === null,
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

    /**
     * What Add user offers: the next ID No. -- today's date in Manila and the next running number --
     * and the departments the actor may add someone to.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function newUserOptions(Employee $actor, Request $request): array
    {
        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => [
                'nextIdNo' => $this->nextIdNo(),
                'departments' => $this->departmentChoices($actor, PortalRules::reach($actor, $request, self::RULE)),
            ],
        ];
    }

    /**
     * What promoting a candidate starts from (2026-10-12): the next free ID No. and every department,
     * since HR files a new hire wherever they are going rather than within its own Users reach.
     *
     * @return array{nextIdNo: string, departments: list<string>}
     */
    public function promotionOptions(Employee $actor): array
    {
        return ['nextIdNo' => $this->nextIdNo(), 'departments' => $this->departmentChoices($actor, 'all')];
    }

    /**
     * Someone new, in the portal before Airtable has them: active, as a User (the role can change
     * after, under its own power and checks), in a department the actor's Users reach covers. The
     * sync creates them in Airtable (2026-10-14, user), so what links them can follow.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addUser(Employee $actor, Request $request): array
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'middleName' => ['nullable', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'idNo' => ['required', 'string', 'max:20'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:100'],
        ], [], ['firstName' => 'first name', 'lastName' => 'last name', 'idNo' => 'ID No.', 'jobTitle' => 'job title']);

        $reach = PortalRules::reach($actor, $request, self::RULE);
        if ($reach !== 'all' && $reach !== 'department') {
            abort(403, 'Adding someone needs Users at your department or every department.');
        }
        $firstName = self::text($data['firstName']);
        $lastName = self::text($data['lastName']);
        if ($firstName === null || $lastName === null) {
            abort(422, 'Give the first and last name.');
        }
        $idNo = trim($data['idNo']);
        if (preg_match(self::ID_NO, $idNo) !== 1) {
            abort(422, 'An ID No. is the start date and a number, like 261006-0021.');
        }
        $email = strtolower(trim($data['email']));
        $department = $this->chosenDepartment($data['department'], $this->departmentChoices($actor, $reach));
        if (Employee::query()->where('id_no', $idNo)->exists()) {
            abort(422, 'Someone already has the ID No. '.$idNo.'.');
        }
        if (Employee::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
            abort(422, 'Someone already has that email address.');
        }

        $employee = $this->createEmployee($actor, [
            'id_no' => $idNo,
            'first_name' => $firstName,
            'middle_name' => self::text($data['middleName'] ?? null),
            'last_name' => $lastName,
            'email' => $email,
            'job_title' => self::text($data['jobTitle'] ?? null),
            'department' => $department,
            'status' => 'Active',
            'role' => 'User',
        ]);
        $this->bumpCache();
        PortalCache::bump();
        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.users',
            PortalActivityCopy::addedUser($employee),
            (string) $employee->getKey(),
            $request,
            ['idNo' => $idNo, 'department' => $department],
        );

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($actor, $employee, $request, $this->seesPrivate($actor, $employee, $request)),
        ];
    }

    /**
     * The department the portal files someone under: their reaches, the projects they may lead and
     * the department filters all follow it. Anyone the actor's Users reach covers may be moved to any
     * department. Synced to Airtable like a role change, since Airtable has the person.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function changeDepartment(Employee $actor, int $id, Request $request): array
    {
        $target = $this->inReach($actor, $id, $request);
        if (! $this->mayChangeDepartment($actor, $target, $request)) {
            abort(403, 'An executive\'s department cannot be changed here.');
        }
        $data = $request->validate(['department' => ['required', 'string', 'max:100']]);
        $department = $this->chosenDepartment($data['department'], $this->departmentChoices($actor, 'all'));

        $previous = self::text($target->department);
        if ($previous === null || strcasecmp($previous, $department) !== 0) {
            $target->department = $department;
            $this->ledger->within($target->getConnection(), function () use ($target, $actor, $previous, $department): void {
                $target->save();
                $this->ledger->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreLedger::PRODUCT_PORTAL.'.employees',
                    [
                        'table' => 'employees',
                        'key' => 'id',
                        'fields' => ['department' => $department],
                        'changes' => ['department' => ['from' => $previous, 'to' => $department]],
                    ],
                    'employees',
                    (string) $target->getKey(),
                    null,
                    (int) $actor->getKey(),
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
            });
            $this->bumpCache();
            PortalCache::bump();
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                'manage.users',
                PortalActivityCopy::changedSomeoneDepartment($target, $department),
                (string) $target->getKey(),
                $request,
                ['previousDepartment' => $previous, 'newDepartment' => $department],
            );
            $this->audit->notifyIfOther(
                $target,
                $actor,
                PortalNotificationType::DEPARTMENT_CHANGED,
                'Department changed',
                PortalActivityCopy::notifyDepartmentChanged($department, PortalActivityCopy::actorName($actor, $request)),
                PortalShellPath::USER_MANAGEMENT,
                'Open users',
                ['recordId' => (string) $target->getKey()],
            );
        }

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($actor, $target, $request, $this->seesPrivate($actor, $target, $request)),
        ];
    }

    /**
     * Activate and deactivate (2026-10-12, user): someone in the actor's Users reach turned Active or
     * Inactive. Inactive cannot sign in, and a session already open stops at its next call. Never the
     * actor themselves or an executive, and a fellow administrator only with Manage other admins.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function changeStatus(Employee $actor, int $id, Request $request): array
    {
        $data = $request->validate(['status' => ['required', 'string', Rule::in(self::STATUSES)]]);
        $target = $this->inReach($actor, $id, $request);
        $refusal = $this->statusRefusal((int) $actor->getKey(), $target, PortalRules::holds($actor, $request, self::PEERS_RULE));
        if ($refusal !== null) {
            abort(403, $refusal);
        }

        $status = $data['status'];
        $previous = self::text($target->status);
        if ($previous === null || strcasecmp($previous, $status) !== 0) {
            $target->status = $status;
            $this->ledger->within($target->getConnection(), function () use ($target, $actor, $previous, $status): void {
                $target->save();
                $this->ledger->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreLedger::PRODUCT_PORTAL.'.employees',
                    [
                        'table' => 'employees',
                        'key' => 'id',
                        'fields' => ['status' => $status],
                        'changes' => ['status' => ['from' => $previous, 'to' => $status]],
                    ],
                    'employees',
                    (string) $target->getKey(),
                    null,
                    (int) $actor->getKey(),
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
            });
            $this->bumpCache();
            PortalCache::bump();
            $name = trim(($target->first_name ?? '').' '.($target->last_name ?? ''));
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                'manage.users',
                '{UserName|You} '.($status === 'Active' ? 'activated' : 'deactivated').' the account of '.$name,
                (string) $target->getKey(),
                $request,
                ['previousStatus' => $previous, 'newStatus' => $status],
            );
        }

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($actor, $target, $request, $this->seesPrivate($actor, $target, $request)),
        ];
    }

    /**
     * Edit employee information (2026-10-12, user): the private details of someone whose information
     * the actor sees, cleaned the way a registration cleans them. Only the details sent change, so an
     * older value the registration rules would refuse never blocks another edit. Portal only: Airtable
     * keeps its own copy of these, and the log names the details changed, never their values.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateInformation(Employee $actor, int $id, Request $request): array
    {
        $target = $this->privateTarget($actor, $id, $request);
        $rules = array_fill_keys(array_keys(self::INFORMATION_COLUMNS), ['sometimes', 'nullable', 'string', 'max:255']);
        $rules['personalEmail'][] = 'email';
        /** @var array<string, string|null> $data */
        $data = $request->validate($rules, [], ['personalEmail' => 'personal email']);
        if ($data === []) {
            abort(422, 'Change at least one detail.');
        }

        $sent = [];
        foreach ($data as $key => $value) {
            $sent[self::REGISTRATION_KEYS[$key] ?? $key] = $value;
        }
        $clean = $this->input->cleaned($sent);

        $changed = [];
        foreach (array_keys($data) as $key) {
            $column = self::INFORMATION_COLUMNS[$key];
            $value = $clean[self::REGISTRATION_KEYS[$key] ?? $key] ?? null;
            if ($key === 'personalEmail') {
                $value = self::text($value) === null ? null : strtolower((string) self::text($value));
            }
            $current = $key === 'dateOfBirth' ? self::text(substr((string) $target->{$column}, 0, 10)) : self::text($target->{$column});
            if ($current !== $value) {
                $target->{$column} = $value;
                $changed[] = $key;
            }
        }

        if ($changed !== []) {
            // Named as a plain list: the ledger strips government and bank keys, and the sync reads SQL.
            $columns = array_values(array_map(static fn (string $key): string => self::INFORMATION_COLUMNS[$key], $changed));
            $this->ledger->within($target->getConnection(), function () use ($target, $actor, $columns): void {
                $target->save();
                $this->ledger->recordEdit(
                    CoreLedger::PRODUCT_PORTAL,
                    CoreLedger::PRODUCT_PORTAL.'.employees',
                    ['table' => 'employees', 'key' => 'id', 'id' => (string) $target->getKey(), 'columns' => $columns],
                    'employees',
                    (string) $target->getKey(),
                    null,
                    (int) $actor->getKey(),
                    is_string($actor->id_no) ? $actor->id_no : null,
                );
            });
            $this->bumpCache();
            PortalCache::bump();
            $this->audit->record(
                $actor,
                PortalLogAction::PATCH,
                self::INFORMATION_RULE,
                '{UserName|You} changed the employee information of '.PortalAccessGraph::name($target),
                (string) $target->getKey(),
                $request,
                ['fields' => $changed],
            );
        }

        return $this->profileResponse($actor, $target, $request);
    }

    /**
     * Edit employee information's documents (2026-10-13, user): a file put in one of their slots, by
     * whoever may change their private details. The log names the slot, never the file.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function uploadDocument(Employee $actor, int $id, Request $request): array
    {
        $target = $this->privateTarget($actor, $id, $request);
        $field = trim((string) $request->input('field', ''));
        $this->employeeDocuments->upload($target, $field, $request->file('file'));
        $this->ledgerDocument($actor, $target, $field);
        $this->documentsChanged($actor, $target, $request, PortalLogAction::POST, $field);

        return $this->profileResponse($actor, $target, $request);
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeDocument(Employee $actor, int $id, int $documentId, Request $request): array
    {
        $target = $this->privateTarget($actor, $id, $request);
        $field = $this->employeeDocuments->remove($target, $documentId);
        $this->ledgerDocument($actor, $target, $field);
        $this->documentsChanged($actor, $target, $request, PortalLogAction::DELETE, $field);

        return $this->profileResponse($actor, $target, $request);
    }

    /**
     * An employee's ID card (2026-10-14, user), for whoever sees their employee information, since it
     * prints their blood type and emergency contact. Making one is logged as a look at those is.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function idCard(Employee $actor, int $id, Request $request): array
    {
        $target = $this->privateTarget($actor, $id, $request);
        $this->recordPrivateView($actor, $request, 'made the ID card of', $target);

        return ['section' => 'manage', 'resource' => 'users', 'data' => $this->idCards->card($target)];
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
            $this->recordPrivateView($viewer, $request, 'viewed the employee information of', $target);
        }

        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($viewer, $target, $request, $withPrivate),
        ];
    }

    // A document slot changed: the sync sends that slot's files to the Airtable field it maps to.
    private function ledgerDocument(Employee $actor, Employee $target, string $field): void
    {
        $this->ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            CoreLedger::PRODUCT_PORTAL.'.employees',
            ['table' => 'employees', 'key' => 'id', 'id' => (string) $target->getKey(), 'columns' => [], 'doc_'.$field => true],
            'employees',
            (string) $target->getKey(),
            null,
            (int) $actor->getKey(),
            is_string($actor->id_no) ? $actor->id_no : null,
        );
    }

    /**
     * A new employee and their ledger entry as one write, so the Airtable sync creates them there and
     * every report, request or project linking them can follow (2026-10-14, user).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createEmployee(Employee $actor, array $attributes): Employee
    {
        $employee = new Employee;

        return $this->ledger->within($employee->getConnection(), function () use ($employee, $attributes, $actor): Employee {
            $employee->forceFill($attributes)->save();
            $this->ledger->recordAdd(
                CoreLedger::PRODUCT_PORTAL,
                'employees:'.$employee->getKey(),
                CoreLedger::PRODUCT_PORTAL.'.employees',
                ['table' => 'employees', 'key' => 'id', 'id' => (string) $employee->getKey(), 'idNo' => $employee->id_no],
                'employees',
                (string) $employee->getKey(),
                (int) $actor->getKey(),
                is_string($actor->id_no) ? $actor->id_no : null,
            );

            return $employee;
        });
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

    private function documentsChanged(Employee $actor, Employee $target, Request $request, string $action, string $field): void
    {
        $this->bumpCache();
        $this->audit->record(
            $actor,
            $action,
            self::INFORMATION_RULE,
            '{UserName|You} changed the documents of '.PortalAccessGraph::name($target),
            (string) $target->getKey(),
            $request,
            ['field' => $field, 'change' => $action === PortalLogAction::DELETE ? 'removed' : 'uploaded'],
        );
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

    /*
     * Each look at private details is logged. A shared page's guest has no employee of their own, so the
     * look is filed under whoever made the share, naming the share (2026-10-14, user).
     */
    private function recordPrivateView(Employee $viewer, Request $request, string $verb, Employee $target): void
    {
        $owner = $viewer;
        $who = '{UserName|You}';
        $guest = $request->attributes->get('portalShare');
        if ($guest instanceof PortalGuest) {
            $share = DB::connection('portal')->table('shared_pages')->where('id', $guest->shareId)->first(['label', 'created_by']);
            $creator = $share === null ? null : Employee::query()->find((int) $share->created_by);
            if ($creator === null) {
                return;
            }
            $owner = $creator;
            $who = 'The shared page "'.$share->label.'" of {UserName|You}';
        }
        $this->audit->record(
            $owner,
            PortalLogAction::VIEW,
            self::INFORMATION_RULE,
            $who.' '.$verb.' '.PortalAccessGraph::name($target),
            (string) $target->getKey(),
            $request,
            ['employeeId' => (int) $target->getKey(), ...($guest instanceof PortalGuest ? ['shareId' => $guest->shareId] : [])],
        );
    }

    // Someone in the Users reach whose private details the viewer sees; anyone else is refused.
    private function privateTarget(Employee $viewer, int $id, Request $request): Employee
    {
        $target = $this->inReach($viewer, $id, $request);
        if (! $this->seesPrivate($viewer, $target, $request)) {
            abort(403, 'This person\'s employee information is not yours to see.');
        }

        return $target;
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    private function profileResponse(Employee $viewer, Employee $target, Request $request): array
    {
        return [
            'section' => 'manage',
            'resource' => 'users',
            'data' => $this->profileData($viewer, $target, $request, true),
        ];
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
            // The department the portal files them under, and where it may move them.
            'canChangeDepartment' => $this->mayChangeDepartment($viewer, $target, $request),
            'departmentOptions' => $this->mayChangeDepartment($viewer, $target, $request) ? $this->departmentChoices($viewer, 'all') : [],
            // Their documents on Cloudinary, and whether this viewer may change their private details (2026-10-12).
            'documents' => $withPrivate ? $this->employeeDocuments->listFor($target) : [],
            'canEditInformation' => $withPrivate && PortalRules::holds($viewer, $request, self::INFORMATION_EDIT_RULE),
        ];
    }

    // Why the viewer may not change this account's status, or null; the power and the reach are checked apart.
    private function statusRefusal(int $viewerId, Employee $target, bool $holdsPeers): ?string
    {
        if ($viewerId === (int) $target->getKey()) {
            return 'You cannot change the status of your own account.';
        }
        if (PortalRole::isLocked($target->role, $target->role_level)) {
            return 'An executive\'s account cannot be activated or deactivated here.';
        }
        if (PortalRole::isAdmin($target->role, $target->role_level) && ! $holdsPeers) {
            return 'A fellow administrator\'s account needs Manage other admins.';
        }

        return null;
    }

    // The route checks the power; the person is already inside the viewer's Users reach to be here.
    private function mayChangeDepartment(Employee $viewer, Employee $target, Request $request): bool
    {
        return PortalRules::holds($viewer, $request, self::DEPARTMENT_RULE)
            && ! PortalRole::isLocked($target->role, $target->role_level);
    }

    /**
     * The departments people work in (company_departments), cut to the actor's own at department reach.
     *
     * @return list<string>
     */
    private function departmentChoices(Employee $actor, ?string $reach): array
    {
        if ($reach !== 'all' && $reach !== 'department') {
            return [];
        }
        // Only the departments people work in (2026-10-14, user): Admin and General are project buckets.
        $names = [];
        foreach (PortalDepartments::names() as $value) {
            $name = self::text(is_string($value) ? $value : null);
            if ($name !== null) {
                $names[PortalPreview::department($name)] ??= $name;
            }
        }
        if ($reach === 'department') {
            $own = PortalPreview::department($actor->department);
            $names = array_intersect_key($names, [$own => true]);
        }
        natcasesort($names);

        return array_values($names);
    }

    /**
     * @param  list<string>  $choices
     */
    private function chosenDepartment(string $value, array $choices): string
    {
        foreach ($choices as $choice) {
            if (strcasecmp($choice, trim($value)) === 0) {
                return $choice;
            }
        }
        abort(422, 'Choose a department from the list.');
    }

    // Today's date in Manila, then the running number after the highest anyone has.
    private function nextIdNo(): string
    {
        $highest = 0;
        foreach (Employee::query()->whereNotNull('id_no')->pluck('id_no') as $value) {
            if (preg_match(self::ID_NO, trim((string) $value)) === 1) {
                $highest = max($highest, (int) substr(trim((string) $value), 7));
            }
        }

        return now('Asia/Manila')->format('ymd').'-'.sprintf('%04d', $highest + 1);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $trimmed === '' ? null : $trimmed;
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
