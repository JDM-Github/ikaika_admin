<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What every person effectively holds: their role preset, plus the powers others gave them, minus
 * the powers others limited. Worked out live from one snapshot of the tables, never stored, so a
 * grant or limit counts only while its maker may still manage that person and still holds the
 * power. Taking a power from someone therefore ends everything they gave from it, and giving it
 * back restores it, without a single row being touched.
 *
 * Normally only someone above a person manages them. Holding "Manage other admins" also lets an
 * admin manage fellow admins (an executive fellow executives); between equals a gift or limit is
 * judged against the maker's base powers (role plus what came from above), never against what
 * equals gave them, so two admins can never keep each other's powers alive in a loop. "Manage other
 * admins" received from an equal still lets them manage equals, never the equal who gave it, and
 * since it is not a base power they can never pass it on.
 *
 * Developers: is_developer grants nothing by itself. Everyone, a developer included, starts from
 * their role (tier), and that default config is what anyone editing them changes. Only the viewer
 * with Developer mode on ($liveDeveloper) holds every rule and acts as a developer. A gift, limit or
 * list made in Developer mode (as_developer) is judged as a developer's, so it survives their
 * signing out of Developer mode and nobody can end it by limiting them; one made in Default is
 * judged on the maker's role like anyone's, even while they are in Developer mode later.
 */
final class PortalAccessGraph
{
    public const TIER_DEVELOPER = 'developer';

    public const TIER_EXECUTIVE = 'executive';

    public const TIER_ADMIN = 'admin';

    public const TIER_MEMBER = 'member';

    public const MANAGE_KEY = 'manage.users.access';

    public const PEERS_KEY = 'manage.users.access.peers';

    // How a Developer mode act is named to anyone not in Developer mode, and the mode to someone who is.
    public const DEVELOPER_NAME = 'a developer';

    public const DEVELOPER_NAME_CAPITAL = 'A developer';

    public const DEVELOPER_MODE_SUFFIX = ' (Developer mode)';

    /**
     * @var array<string, int>
     */
    private const TIER_RANK = [self::TIER_MEMBER => 1, self::TIER_ADMIN => 2, self::TIER_EXECUTIVE => 3, self::TIER_DEVELOPER => 4];

    /**
     * @var array<string, int>
     */
    private const REACH_RANK = ['own' => 1, 'team' => 2, 'department' => 3, 'all' => 4];

    /**
     * @var array<string, array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>>
     */
    private array $memo = [];

    /**
     * @var array<string, true>
     */
    private array $computing = [];

    // The same tables with nobody in Developer mode, for judging what the live developer made in Default.
    private ?self $asRoles = null;

    /**
     * @param  array<string, object>  $catalog  Rules keyed by permission_key, in catalog order.
     * @param  array<string, array<string, array{reach: ?string, executives: bool}>>  $presets  Role => rule => reach.
     * @param  array<int, Employee>  $employees
     * @param  list<object>  $grants
     * @param  list<object>  $limits
     * @param  list<object>  $lists
     * @param  array<int, array<string, ?string>>  $personal  Employee => rule => reach: their own defaults.
     */
    private function __construct(
        private readonly array $catalog,
        private readonly array $presets,
        private readonly array $employees,
        private readonly array $grants,
        private readonly array $limits,
        private readonly array $lists,
        private readonly array $personal,
        private readonly ?int $liveDeveloper,
    ) {}

    // The graph as this viewer sees it: every rule when their Developer mode is on.
    public static function forViewer(Employee $viewer, Request $request): self
    {
        return self::load(PortalDeveloperMode::isOn($viewer, $request) ? (int) $viewer->getKey() : null);
    }

    public static function load(?int $liveDeveloper = null): self
    {
        $connection = DB::connection('portal');

        $catalog = [];
        foreach ($connection->table('access_permissions')->orderBy('sort_order')->orderBy('permission_key')->get() as $row) {
            $catalog[(string) $row->permission_key] = $row;
        }

        $presets = [];
        foreach ($connection->table('access_presets')->get() as $row) {
            $presets[(string) $row->role][(string) $row->permission_key] = [
                'reach' => $row->reach,
                'executives' => (bool) $row->include_executives,
            ];
        }

        $personal = [];
        foreach ($connection->table('access_personal')->get() as $row) {
            $personal[(int) $row->employee_id][(string) $row->permission_key] = $row->reach;
        }

        $employees = [];
        foreach (Employee::query()->get(['id', 'first_name', 'last_name', 'id_no', 'department', 'role', 'role_level', 'is_developer', 'status']) as $employee) {
            $employees[(int) $employee->getKey()] = $employee;
        }

        return new self(
            $catalog,
            $presets,
            $employees,
            $connection->table('access_grants')->orderBy('id')->get()->all(),
            $connection->table('access_limits')->orderBy('id')->get()->all(),
            $connection->table('access_lists')->orderBy('id')->get()->all(),
            $personal,
            $liveDeveloper,
        );
    }

    /**
     * The preset a person starts from, by role alone: executive, then admin, then member. The
     * retired ProjectAdmin role is a plain member, and is_developer changes nothing here.
     */
    public static function tier(Employee $employee): string
    {
        if (PortalRole::isExecutive($employee->role, $employee->role_level)) {
            return self::TIER_EXECUTIVE;
        }

        return PortalRole::isAdmin($employee->role, $employee->role_level) ? self::TIER_ADMIN : self::TIER_MEMBER;
    }

    // Developer only for the viewer whose Developer mode is on; everyone else acts as their role.
    public function actingTier(int $employeeId): string
    {
        $employee = $this->employees[$employeeId] ?? null;
        if ($employee === null) {
            return self::TIER_MEMBER;
        }

        return $employeeId === $this->liveDeveloper ? self::TIER_DEVELOPER : self::tier($employee);
    }

    public static function reachRank(?string $reach): int
    {
        return self::REACH_RANK[$reach ?? ''] ?? 0;
    }

    /**
     * @return array<string, object>
     */
    public function catalog(): array
    {
        return $this->catalog;
    }

    public function employee(int $id): ?Employee
    {
        return $this->employees[$id] ?? null;
    }

    /**
     * @return array<int, Employee>
     */
    public function employees(): array
    {
        return $this->employees;
    }

    /**
     * Every rule the person effectively holds, keyed by rule, in catalog order.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>
     */
    public function effective(int $employeeId): array
    {
        return $this->compute($employeeId, true);
    }

    /**
     * The rules a maker may hand to, or limit on, this target: everything they hold for someone
     * below them, only their base rules for an equal.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>
     */
    public function powersFor(int $makerId, int $targetId): array
    {
        return $this->compute($makerId, ! $this->areEquals($makerId, $targetId, null));
    }

    /**
     * Whether the person's role is high enough to hold the rule at all: "Manage other admins" is
     * for administrators and above, so it is never theirs as a member, whoever gives it.
     */
    public function canHold(int $employeeId, string $key): bool
    {
        $employee = $this->employees[$employeeId] ?? null;
        $minimum = $this->catalog[$key]->min_tier ?? null;
        $department = $this->catalog[$key]->only_department ?? null;
        // A department-only rule is an admin's only while they are in that department; executives and Developer mode always may.
        if ($employee !== null && $department !== null
            && ! in_array($this->actingTier($employeeId), [self::TIER_EXECUTIVE, self::TIER_DEVELOPER], true)
            && PortalPreview::department($employee->department) !== PortalPreview::department($department)) {
            return false;
        }

        return $employee !== null && ($minimum === null || self::TIER_RANK[$this->actingTier($employeeId)] >= (self::TIER_RANK[$minimum] ?? PHP_INT_MAX));
    }

    // Why the person can never hold the rule, for a refusal; null when they can.
    public function holdRefusal(int $employeeId, string $key): ?string
    {
        if ($this->canHold($employeeId, $key)) {
            return null;
        }
        $department = $this->catalog[$key]->only_department ?? null;

        return $department !== null && ($this->catalog[$key]->min_tier ?? null) !== self::TIER_DEVELOPER
            && self::TIER_RANK[$this->actingTier($employeeId)] >= self::TIER_RANK[self::TIER_ADMIN]
            ? 'Only executives and '.$department.' administrators can hold that power.'
            : 'Only an administrator or above can hold that power.';
    }

    /**
     * Where the person starts, before any gift or limit: their role preset and their own defaults,
     * each only where their role and department may hold it.
     *
     * @return array<string, array{reach: ?string, executives: bool, personal: bool}>
     */
    private function startingRules(int $employeeId): array
    {
        $employee = $this->employees[$employeeId] ?? null;
        if ($employee === null) {
            return [];
        }
        $rules = [];
        foreach ($this->presets[self::tier($employee)] ?? [] as $key => $preset) {
            if ($this->canHold($employeeId, $key)) {
                $rules[$key] = ['reach' => $preset['reach'], 'executives' => $preset['executives'], 'personal' => false];
            }
        }
        foreach ($this->personal[$employeeId] ?? [] as $key => $reach) {
            if (! isset($this->catalog[$key]) || ! $this->canHold($employeeId, $key)) {
                continue;
            }
            $current = $rules[$key] ?? null;
            $rules[$key] = [
                'reach' => self::reachRank($reach) > self::reachRank($current['reach'] ?? null) ? $reach : ($current['reach'] ?? null),
                'executives' => $current['executives'] ?? false,
                'personal' => $current === null,
            ];
        }

        return $rules;
    }

    // Held only in Developer mode, so no gift or limit can ever reach it, even from another developer.
    public function isDeveloperOnly(string $key): bool
    {
        return ($this->catalog[$key]->min_tier ?? null) === self::TIER_DEVELOPER;
    }

    // A rule whose people can be narrowed with a white or black list.
    public function supportsList(string $key): bool
    {
        return (bool) ($this->catalog[$key]->supports_list ?? false);
    }

    /**
     * The white and black lists on a person's rule that count right now: like a limit, each counts
     * only while its maker may still manage them and holds the rule. A maker's Developer mode list
     * and Default list are two lists.
     *
     * @return list<array{maker: int, asDeveloper: bool, mode: string, ids: list<int>}>
     */
    public function listsOn(int $targetId, string $key): array
    {
        $lists = [];
        $refused = [];
        foreach ($this->lists as $row) {
            $makerId = (int) $row->set_by;
            $asDeveloper = (bool) $row->as_developer;
            $slot = $makerId.':'.($asDeveloper ? 'dev' : 'role');
            if ((int) $row->employee_id !== $targetId || $row->permission_key !== $key || isset($refused[$slot])) {
                continue;
            }
            if (! isset($lists[$slot])) {
                if ($this->validMaker($makerId, $targetId, $key, $asDeveloper) === null) {
                    $refused[$slot] = true;

                    continue;
                }
                $lists[$slot] = ['maker' => $makerId, 'asDeveloper' => $asDeveloper, 'mode' => (string) $row->mode, 'ids' => []];
            }
            $lists[$slot]['ids'][] = (int) $row->subject_id;
        }

        return array_values($lists);
    }

    /**
     * The person's rules, from their role and the people above them; with $withEquals, also from
     * the equals who may manage them. Equals are judged on these base rules alone.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>
     */
    private function compute(int $employeeId, bool $withEquals): array
    {
        $slot = $employeeId.($withEquals ? ':full' : ':base');
        if (isset($this->memo[$slot])) {
            return $this->memo[$slot];
        }
        // Equals are judged on base rules, which never look sideways, so this guard is only a backstop.
        if (isset($this->computing[$slot])) {
            return [];
        }
        $employee = $this->employees[$employeeId] ?? null;
        if ($employee === null || ! self::isActive($employee)) {
            return $this->memo[$slot] = [];
        }

        if ($employeeId === $this->liveDeveloper) {
            return $this->memo[$slot] = $this->everything();
        }

        $this->computing[$slot] = true;
        $rules = [];
        // Their role preset, then their own defaults on top: both count as where they start, so a limit takes either away.
        foreach ($this->startingRules($employeeId) as $key => $start) {
            $rules[$key] = ['reach' => $start['reach'], 'executives' => $start['executives'], 'fromRole' => true, 'givenBy' => [], 'givenByDeveloper' => []]
                + ($start['personal'] ? ['personal' => true] : []);
        }

        foreach ($this->grants as $grant) {
            if ((int) $grant->employee_id !== $employeeId) {
                continue;
            }
            $giverId = (int) $grant->granted_by;
            $asDeveloper = (bool) $grant->as_developer;
            if ((! $withEquals && $this->areEquals($giverId, $employeeId, $asDeveloper)) || ! $this->canHold($employeeId, (string) $grant->permission_key)) {
                continue;
            }
            $held = $this->validMaker($giverId, $employeeId, (string) $grant->permission_key, $asDeveloper);
            if ($held === null) {
                continue;
            }
            $reach = self::reachRank($grant->reach) <= self::reachRank($held['reach']) ? $grant->reach : $held['reach'];
            $executives = (bool) $grant->include_executives && $held['executives'];
            $key = (string) $grant->permission_key;
            $current = $rules[$key] ?? ['reach' => null, 'executives' => false, 'fromRole' => false, 'givenBy' => [], 'givenByDeveloper' => []];
            $rules[$key] = [
                'reach' => self::reachRank($reach) > self::reachRank($current['reach']) ? $reach : $current['reach'],
                'executives' => $current['executives'] || $executives,
                'fromRole' => $current['fromRole'],
                'givenBy' => array_values(array_unique([...$current['givenBy'], $giverId])),
                'givenByDeveloper' => $asDeveloper ? array_values(array_unique([...$current['givenByDeveloper'], $giverId])) : $current['givenByDeveloper'],
            ];
        }

        foreach ($this->limits as $limit) {
            $asDeveloper = (bool) $limit->as_developer;
            if ((int) $limit->employee_id !== $employeeId || (! $withEquals && $this->areEquals((int) $limit->limited_by, $employeeId, $asDeveloper))) {
                continue;
            }
            if ($this->validMaker((int) $limit->limited_by, $employeeId, (string) $limit->permission_key, $asDeveloper) !== null) {
                foreach ($this->subtree((string) $limit->permission_key) as $key) {
                    unset($rules[$key]);
                }
            }
        }

        unset($this->computing[$slot]);

        return $this->memo[$slot] = $this->ordered($rules);
    }

    /**
     * Whether one person may give powers to, or limit, another: never themselves; someone below
     * their own role; an equal only while they hold "Manage other admins"; and only while they hold
     * Access Rules with a reach that covers that person.
     */
    public function canManage(int $makerId, int $targetId): bool
    {
        return $this->manages($makerId, $targetId, true);
    }

    /**
     * With $borrowed, "Manage other admins" received from an equal counts too, except over whoever
     * gave it. Without it only a base "Manage other admins" does, which is how an equal's gift is
     * judged, so borrowing never leans on another borrowed power and cannot loop.
     */
    private function manages(int $makerId, int $targetId, bool $borrowed): bool
    {
        $maker = $this->employees[$makerId] ?? null;
        $target = $this->employees[$targetId] ?? null;
        if ($maker === null || $target === null || $makerId === $targetId || ! self::isActive($maker) || ! self::isActive($target)) {
            return false;
        }

        // A developer is managed as their role, so an admin edits a member developer's default config.
        $makerRank = self::TIER_RANK[$this->actingTier($makerId)];
        $targetRank = self::TIER_RANK[self::tier($target)];
        if ($makerRank > $targetRank) {
            $rules = $this->effective($makerId);
        } elseif ($makerRank === $targetRank) {
            $rules = $this->compute($makerId, false);
            if (! isset($rules[self::PEERS_KEY])) {
                // Borrowed from an equal: it counts only while it survives every limit, and never over a lender.
                $held = $borrowed ? ($this->compute($makerId, true)[self::PEERS_KEY] ?? null) : null;
                if ($held === null || in_array($targetId, $held['givenBy'], true)) {
                    return false;
                }
            }
        } else {
            return false;
        }

        $manage = $rules[self::MANAGE_KEY] ?? null;
        if ($manage === null) {
            return false;
        }

        return match ($manage['reach']) {
            'all' => true,
            'department' => PortalPreview::department($maker->department) !== ''
                && PortalPreview::department($maker->department) === PortalPreview::department($target->department),
            default => false,
        };
    }

    /**
     * How far the person would hold a rule without one maker's gift (in one mode, or either when
     * null): their role plus every other gift. Tells an editor whether switching off takes back or limits.
     *
     * @return array{holds: bool, reach: ?string}
     */
    public function without(int $targetId, string $key, int $makerId, ?bool $asDeveloper = null): array
    {
        $start = $this->startingRules($targetId)[$key] ?? null;
        $holds = $start !== null;
        $reach = $start['reach'] ?? null;
        foreach ($this->grantsOn($targetId) as $grant) {
            $isTheirs = (int) $grant->granted_by === $makerId && ($asDeveloper === null || (bool) $grant->as_developer === $asDeveloper);
            if ($grant->permission_key !== $key || $isTheirs) {
                continue;
            }
            $held = $this->validMaker((int) $grant->granted_by, $targetId, $key, (bool) $grant->as_developer);
            if ($held === null) {
                continue;
            }
            $holds = true;
            $capped = self::reachRank($grant->reach) <= self::reachRank($held['reach']) ? $grant->reach : $held['reach'];
            $reach = self::reachRank($capped) > self::reachRank($reach) ? $capped : $reach;
        }

        return ['holds' => $holds, 'reach' => $reach];
    }

    /**
     * The rule and everything under it, root first.
     *
     * @return list<string>
     */
    public function subtree(string $key): array
    {
        $keys = [$key];
        foreach ($this->catalog as $childKey => $rule) {
            if ($rule->parent_key === $key) {
                array_push($keys, ...$this->subtree($childKey));
            }
        }

        return $keys;
    }

    /**
     * The rule's ancestors, nearest last, so they can be given before it.
     *
     * @return list<string>
     */
    public function ancestors(string $key): array
    {
        $ancestors = [];
        for ($parent = $this->catalog[$key]->parent_key ?? null; $parent !== null; $parent = $this->catalog[$parent]->parent_key ?? null) {
            array_unshift($ancestors, (string) $parent);
        }

        return $ancestors;
    }

    /**
     * Labels repeat across branches ("Approve or reject"), so a log names the whole path.
     */
    public function path(string $key): string
    {
        $labels = [];
        foreach ([...$this->ancestors($key), $key] as $current) {
            $labels[] = (string) ($this->catalog[$current]->label ?? $current);
        }

        return implode(' / ', $labels);
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function names(array $ids): array
    {
        $names = [];
        foreach ($ids as $id) {
            $employee = $this->employees[$id] ?? null;
            if ($employee !== null) {
                $names[] = self::name($employee);
            }
        }

        return $names;
    }

    /**
     * Makers as a viewer should read them: what someone did in Developer mode is "a developer" to a
     * viewer who is not in Developer mode, and their name with the mode to one who is.
     *
     * @param  list<array{id: int, asDeveloper: bool}>  $makers
     * @return list<string>
     */
    public function makerNames(array $makers, bool $viewerIsDeveloper): array
    {
        $names = [];
        foreach ($makers as $maker) {
            $employee = $this->employees[$maker['id']] ?? null;
            if ($employee === null) {
                continue;
            }
            $names[] = match (true) {
                ! $maker['asDeveloper'] => self::name($employee),
                $viewerIsDeveloper => self::name($employee).self::DEVELOPER_MODE_SUFFIX,
                default => self::DEVELOPER_NAME,
            };
        }

        return array_values(array_unique($names));
    }

    /**
     * Who gave a rule, each with the mode their counted gift was made in.
     *
     * @param  array{givenBy: list<int>, givenByDeveloper: list<int>}  $rule
     * @return list<array{id: int, asDeveloper: bool}>
     */
    public static function givers(array $rule): array
    {
        return array_map(
            static fn (int $id): array => ['id' => $id, 'asDeveloper' => in_array($id, $rule['givenByDeveloper'], true)],
            $rule['givenBy'],
        );
    }

    public static function name(Employee $employee): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($employee->first_name) ? $employee->first_name : null,
            is_string($employee->last_name) ? $employee->last_name : null,
        );
    }

    /**
     * @return list<object>
     */
    public function grantsOn(int $targetId): array
    {
        return array_values(array_filter($this->grants, static fn (object $grant): bool => (int) $grant->employee_id === $targetId));
    }

    /**
     * @return list<object>
     */
    public function limitsOn(int $targetId): array
    {
        return array_values(array_filter($this->limits, static fn (object $limit): bool => (int) $limit->employee_id === $targetId));
    }

    /**
     * What the maker holds of a rule, when their grant, limit or list on the target still counts.
     * One made in Developer mode counts while the maker is still a developer; one made in Default
     * is judged on the maker's role, whatever mode they are in now.
     *
     * @return array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}|null
     */
    public function validMaker(int $makerId, int $targetId, string $key, bool $asDeveloper = false): ?array
    {
        if ($asDeveloper) {
            return $this->isDeveloperMaker($makerId, $targetId)
                ? ['reach' => 'all', 'executives' => true, 'fromRole' => true, 'givenBy' => [], 'givenByDeveloper' => []]
                : null;
        }
        $judge = $this->judgeOf($makerId);
        if (! $judge->canManage($makerId, $targetId)) {
            return null;
        }

        return $judge->powersFor($makerId, $targetId)[$key] ?? null;
    }

    // The graph that judges what this maker did in Default: the one with nobody live when it is them.
    private function judgeOf(int $makerId): self
    {
        if ($makerId !== $this->liveDeveloper) {
            return $this;
        }

        return $this->asRoles ??= new self($this->catalog, $this->presets, $this->employees, $this->grants, $this->limits, $this->lists, $this->personal, null);
    }

    // Still a developer and still active: what they made in Developer mode keeps counting.
    private function isDeveloperMaker(int $makerId, int $targetId): bool
    {
        $maker = $this->employees[$makerId] ?? null;

        return $maker !== null && (bool) $maker->is_developer && $makerId !== $targetId
            && isset($this->employees[$targetId]) && self::isActive($maker);
    }

    /**
     * Same role tier: two admins, two executives. For a stored row ($asDeveloper set) it is the
     * maker's role, and a Developer mode row is never an equal's; for someone acting now (null) it
     * is how they act, so a developer in Developer mode is nobody's equal.
     */
    private function areEquals(int $makerId, int $targetId, ?bool $asDeveloper): bool
    {
        $maker = $this->employees[$makerId] ?? null;
        $target = $this->employees[$targetId] ?? null;
        if ($maker === null || $target === null) {
            return false;
        }
        if ($asDeveloper === null) {
            return $this->actingTier($makerId) === self::tier($target);
        }
        if ($asDeveloper && $this->isDeveloperMaker($makerId, $targetId)) {
            return false;
        }

        return self::tier($maker) === self::tier($target);
    }

    /**
     * Every rule in the catalog at its widest, for the viewer in Developer mode.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>
     */
    private function everything(): array
    {
        $rules = [];
        foreach ($this->catalog as $key => $row) {
            $rules[$key] = ['reach' => (bool) $row->supports_reach ? 'all' : null, 'executives' => true, 'fromRole' => true, 'givenBy' => [], 'givenByDeveloper' => []];
        }

        return $rules;
    }

    private static function isActive(Employee $employee): bool
    {
        return PortalRole::normalize($employee->status) === 'active';
    }

    /**
     * Catalog order, and a rule whose parent is not held is dropped: a tab without its page is
     * nowhere to be.
     *
     * @param  array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>  $rules
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>, givenByDeveloper: list<int>}>
     */
    private function ordered(array $rules): array
    {
        $ordered = [];
        foreach ($this->catalog as $key => $rule) {
            if (! isset($rules[$key])) {
                continue;
            }
            $parent = $rule->parent_key;
            if ($parent === null || isset($ordered[$parent])) {
                $ordered[$key] = $rules[$key];
            }
        }

        return $ordered;
    }
}
