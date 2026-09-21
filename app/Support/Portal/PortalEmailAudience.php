<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Who a Send Email reaches, and who the composer may choose from.
 *
 * An audience is everyone, a department, a role, or hand-picked members. The picker is fed
 * from the same active roster the send resolves against, so a name on the form is a name that
 * receives the mail. A member with no usable address is not a recipient and not a pick: the
 * send only counts people it can actually reach.
 */
final class PortalEmailAudience
{
    public const EVERYONE = 'everyone';

    public const DEPARTMENT = 'department';

    public const ROLE = 'role';

    public const MEMBERS = 'members';

    /**
     * @var list<string>
     */
    public const AUDIENCES = [self::EVERYONE, self::DEPARTMENT, self::ROLE, self::MEMBERS];

    public const CACHE_TTL_SECONDS = 30;

    private const CACHE_VERSION_KEY = 'portal:administration:email:audience:version';

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array{
     *         audiences: list<array{value: string, label: string, count: int}>,
     *         categories: list<string>,
     *         departments: list<array{value: string, label: string, count: int}>,
     *         roles: list<array{value: string, label: string, count: int}>,
     *         members: list<array{id: int, name: string, department: ?string}>,
     *         total: int
     *     }
     * }
     */
    public function options(): array
    {
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return Cache::remember(
            'portal:administration:email:audience:'.$version,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildOptions(),
        );
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    public static function audience(mixed $value): ?string
    {
        $audience = strtolower(trim((string) $value));

        return in_array($audience, self::AUDIENCES, true) ? $audience : null;
    }

    /**
     * The filter a send should store: only the picks that audience actually uses, each one
     * checked against the roster the form was built from.
     *
     * @return array{departments: list<string>, roles: list<string>, memberIds: list<int>}
     */
    public function validatedFilter(string $audience, Request $request): array
    {
        if ($audience === self::DEPARTMENT) {
            return ['departments' => $this->validatedDepartments($request->input('departments', [])), 'roles' => [], 'memberIds' => []];
        }

        if ($audience === self::ROLE) {
            return ['departments' => [], 'roles' => $this->validatedRoles($request->input('roles', [])), 'memberIds' => []];
        }

        if ($audience === self::MEMBERS) {
            return ['departments' => [], 'roles' => [], 'memberIds' => $this->validatedMemberIds($request->input('memberIds', []))];
        }

        return ['departments' => [], 'roles' => [], 'memberIds' => []];
    }

    /**
     * Everyone the audience reaches, in roster order. Addresses stay in this list and never
     * leave it: nothing a caller stores or logs comes from here but the count.
     *
     * @param  array{departments: list<string>, roles: list<string>, memberIds: list<int>}  $filter
     * @return list<array{id: int, name: string, email: string}>
     */
    public function recipients(string $audience, array $filter): array
    {
        $query = Employee::query()
            ->select(['id', 'first_name', 'last_name', 'email'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');

        if ($audience === self::DEPARTMENT) {
            $this->matchAny($query, 'department', $filter['departments']);
        } elseif ($audience === self::ROLE) {
            $this->matchAny($query, 'role', $filter['roles']);
        } elseif ($audience === self::MEMBERS) {
            if ($filter['memberIds'] === []) {
                return [];
            }
            $query->whereIn('id', $filter['memberIds']);
        }

        $recipients = [];
        foreach ($query->get() as $row) {
            $email = trim((string) ($row->email ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $recipients[] = [
                'id' => (int) $row->id,
                'name' => $this->nameOf($row),
                'email' => $email,
            ];
        }

        return $recipients;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: array{
     *         audiences: list<array{value: string, label: string, count: int}>,
     *         categories: list<string>,
     *         departments: list<array{value: string, label: string, count: int}>,
     *         roles: list<array{value: string, label: string, count: int}>,
     *         members: list<array{id: int, name: string, department: ?string}>,
     *         total: int
     *     }
     * }
     */
    private function buildOptions(): array
    {
        $rows = Employee::query()
            ->toBase()
            ->select(['id', 'first_name', 'last_name', 'email', 'department', 'role'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();

        $departments = [];
        $roles = [];
        $members = [];
        $reachable = 0;

        foreach ($rows as $row) {
            $email = trim((string) ($row->email ?? ''));
            $canReceive = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            if ($canReceive) {
                $reachable++;
            }

            $department = trim((string) ($row->department ?? ''));
            if ($department !== '' && $canReceive) {
                $key = strtolower($department);
                $departments[$key] = ['value' => $department, 'count' => ($departments[$key]['count'] ?? 0) + 1];
            }

            $role = trim((string) ($row->role ?? ''));
            if ($role !== '' && $canReceive) {
                $key = strtolower($role);
                $roles[$key] = ['value' => $role, 'count' => ($roles[$key]['count'] ?? 0) + 1];
            }

            $name = $this->nameOf($row);
            if ($name === '' || ! $canReceive) {
                continue;
            }
            $members[] = [
                'id' => (int) $row->id,
                'name' => $name,
                'department' => $department === '' ? null : $department,
            ];
        }

        ksort($departments);
        ksort($roles);

        return [
            'section' => 'administration',
            'resource' => 'email-audiences',
            'data' => [
                'audiences' => [
                    ['value' => self::EVERYONE, 'label' => 'Everyone', 'count' => $reachable],
                    ['value' => self::DEPARTMENT, 'label' => 'A department', 'count' => count($departments)],
                    ['value' => self::ROLE, 'label' => 'A role', 'count' => count($roles)],
                    ['value' => self::MEMBERS, 'label' => 'Hand-picked members', 'count' => count($members)],
                ],
                'categories' => PortalEmail::CATEGORIES,
                'departments' => $this->optionList($departments),
                'roles' => $this->optionList($roles),
                'members' => $members,
                'total' => $reachable,
            ],
        ];
    }

    /**
     * @param  array<string, array{value: string, count: int}>  $grouped
     * @return list<array{value: string, label: string, count: int}>
     */
    private function optionList(array $grouped): array
    {
        $options = [];
        foreach ($grouped as $entry) {
            $options[] = ['value' => $entry['value'], 'label' => $entry['value'], 'count' => $entry['count']];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function validatedDepartments(mixed $value): array
    {
        $chosen = $this->textList($value);
        if ($chosen === []) {
            abort(422, 'Choose at least one department.');
        }

        $known = $this->knownValues('department');
        $departments = [];
        foreach ($chosen as $department) {
            $match = $known[strtolower($department)] ?? null;
            if ($match === null) {
                abort(422, 'Choose departments from the list the form offers.');
            }
            $departments[$match] = true;
        }

        return array_keys($departments);
    }

    /**
     * @return list<string>
     */
    private function validatedRoles(mixed $value): array
    {
        $chosen = $this->textList($value);
        if ($chosen === []) {
            abort(422, 'Choose at least one role.');
        }

        $known = $this->knownValues('role');
        $roles = [];
        foreach ($chosen as $role) {
            $match = $known[strtolower($role)] ?? null;
            if ($match === null) {
                abort(422, 'Choose roles from the list the form offers.');
            }
            $roles[$match] = true;
        }

        return array_keys($roles);
    }

    /**
     * @return list<int>
     */
    private function validatedMemberIds(mixed $value): array
    {
        $chosen = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            if (is_numeric($entry) && (int) $entry > 0) {
                $chosen[(int) $entry] = true;
            }
        }

        if ($chosen === []) {
            abort(422, 'Choose at least one member.');
        }

        $known = [];
        foreach (
            Employee::query()
                ->toBase()
                ->select(['id'])
                ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
                ->whereIn('id', array_keys($chosen))
                ->pluck('id') as $id
        ) {
            $known[(int) $id] = true;
        }

        $memberIds = [];
        foreach (array_keys($chosen) as $id) {
            if (! isset($known[$id])) {
                abort(422, 'Choose members from the list the form offers.');
            }
            $memberIds[] = $id;
        }

        return $memberIds;
    }

    /**
     * Names are stored as typed on the roster, so a pick matches on what it says, not on case.
     *
     * @param  list<string>  $values
     */
    private function matchAny(Builder $query, string $column, array $values): void
    {
        $query->where(function (Builder $builder) use ($column, $values): void {            foreach ($values as $value) {
                $builder->orWhereRaw('LOWER(TRIM('.$column.')) = ?', [strtolower($value)]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function knownValues(string $column): array
    {
        $known = [];
        foreach (
            Employee::query()
                ->toBase()
                ->select([$column])
                ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->pluck($column) as $value
        ) {
            $text = trim((string) $value);
            if ($text !== '') {
                $known[strtolower($text)] = $text;
            }
        }

        return $known;
    }

    /**
     * @return list<string>
     */
    private function textList(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $entry) {
            $text = trim((string) $entry);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @param  object{first_name?: mixed, last_name?: mixed}  $row
     */
    private function nameOf(object $row): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($row->first_name ?? null) ? $row->first_name : null,
            is_string($row->last_name ?? null) ? $row->last_name : null,
        );
    }
}
