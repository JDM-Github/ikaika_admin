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
 * equals gave them, so two admins can never keep each other's powers alive in a loop.
 *
 * Developers: is_developer grants nothing by itself. Everyone, a developer included, starts from
 * their role (tier), and that default config is what anyone editing them changes. Only the viewer
 * with Developer mode on ($liveDeveloper) holds every rule and acts as a developer. A gift or limit
 * a developer made is judged as a developer's, so it survives their signing out of Developer mode
 * and nobody can end it by limiting them.
 */
final class PortalAccessGraph
{
    public const TIER_DEVELOPER = 'developer';

    public const TIER_EXECUTIVE = 'executive';

    public const TIER_ADMIN = 'admin';

    public const TIER_MEMBER = 'member';

    public const MANAGE_KEY = 'manage.users.access';

    public const PEERS_KEY = 'manage.users.access.peers';

    /**
     * @var array<string, int>
     */
    private const TIER_RANK = [self::TIER_MEMBER => 1, self::TIER_ADMIN => 2, self::TIER_EXECUTIVE => 3, self::TIER_DEVELOPER => 4];

    /**
     * @var array<string, int>
     */
    private const REACH_RANK = ['own' => 1, 'team' => 2, 'department' => 3, 'all' => 4];

    /**
     * @var array<string, array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>>
     */
    private array $memo = [];

    /**
     * @var array<string, true>
     */
    private array $computing = [];

    /**
     * @param  array<string, object>  $catalog  Rules keyed by permission_key, in catalog order.
     * @param  array<string, array<string, array{reach: ?string, executives: bool}>>  $presets  Role => rule => reach.
     * @param  array<int, Employee>  $employees
     * @param  list<object>  $grants
     * @param  list<object>  $limits
     */
    private function __construct(
        private readonly array $catalog,
        private readonly array $presets,
        private readonly array $employees,
        private readonly array $grants,
        private readonly array $limits,
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
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>
     */
    public function effective(int $employeeId): array
    {
        return $this->compute($employeeId, true);
    }

    /**
     * The rules a maker may hand to, or limit on, this target: everything they hold for someone
     * below them, only their base rules for an equal.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>
     */
    public function powersFor(int $makerId, int $targetId): array
    {
        return $this->compute($makerId, ! $this->areEquals($makerId, $targetId));
    }

    /**
     * Whether the person's role is high enough to hold the rule at all: "Manage other admins" is
     * for administrators and above, so it is never theirs as a member, whoever gives it.
     */
    public function canHold(int $employeeId, string $key): bool
    {
        $employee = $this->employees[$employeeId] ?? null;
        $minimum = $this->catalog[$key]->min_tier ?? null;

        return $employee !== null && ($minimum === null || self::TIER_RANK[$this->actingTier($employeeId)] >= (self::TIER_RANK[$minimum] ?? PHP_INT_MAX));
    }

    // Held only in Developer mode, so no gift or limit can ever reach it, even from another developer.
    public function isDeveloperOnly(string $key): bool
    {
        return ($this->catalog[$key]->min_tier ?? null) === self::TIER_DEVELOPER;
    }

    /**
     * The person's rules, from their role and the people above them; with $withEquals, also from
     * the equals who may manage them. Equals are judged on these base rules alone.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>
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
        foreach ($this->presets[self::tier($employee)] ?? [] as $key => $preset) {
            $rules[$key] = ['reach' => $preset['reach'], 'executives' => $preset['executives'], 'fromRole' => true, 'givenBy' => []];
        }

        foreach ($this->grants as $grant) {
            if ((int) $grant->employee_id !== $employeeId) {
                continue;
            }
            $giverId = (int) $grant->granted_by;
            if ((! $withEquals && $this->areEquals($giverId, $employeeId)) || ! $this->canHold($employeeId, (string) $grant->permission_key)) {
                continue;
            }
            $held = $this->validMaker($giverId, $employeeId, (string) $grant->permission_key);
            if ($held === null) {
                continue;
            }
            $reach = self::reachRank($grant->reach) <= self::reachRank($held['reach']) ? $grant->reach : $held['reach'];
            $executives = (bool) $grant->include_executives && $held['executives'];
            $key = (string) $grant->permission_key;
            $current = $rules[$key] ?? ['reach' => null, 'executives' => false, 'fromRole' => false, 'givenBy' => []];
            $rules[$key] = [
                'reach' => self::reachRank($reach) > self::reachRank($current['reach']) ? $reach : $current['reach'],
                'executives' => $current['executives'] || $executives,
                'fromRole' => $current['fromRole'],
                'givenBy' => array_values(array_unique([...$current['givenBy'], $giverId])),
            ];
        }

        foreach ($this->limits as $limit) {
            if ((int) $limit->employee_id !== $employeeId || (! $withEquals && $this->areEquals((int) $limit->limited_by, $employeeId))) {
                continue;
            }
            if ($this->validMaker((int) $limit->limited_by, $employeeId, (string) $limit->permission_key) !== null) {
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
                return false;
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
     * How far the person would hold a rule without one maker's gift: their role plus everyone
     * else's gifts. Tells an editor whether switching a power off takes back or limits.
     *
     * @return array{holds: bool, reach: ?string}
     */
    public function without(int $targetId, string $key, int $makerId): array
    {
        $employee = $this->employees[$targetId] ?? null;
        $preset = $employee === null ? null : ($this->presets[self::tier($employee)][$key] ?? null);
        $holds = $preset !== null;
        $reach = $preset['reach'] ?? null;
        foreach ($this->grantsOn($targetId) as $grant) {
            if ($grant->permission_key !== $key || (int) $grant->granted_by === $makerId) {
                continue;
            }
            $held = $this->validMaker((int) $grant->granted_by, $targetId, $key);
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
     * What the maker holds of a rule, when their grant or limit on the target still counts.
     *
     * @return array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}|null
     */
    public function validMaker(int $makerId, int $targetId, string $key): ?array
    {
        if ($this->isDeveloperMaker($makerId, $targetId)) {
            return ['reach' => 'all', 'executives' => true, 'fromRole' => true, 'givenBy' => []];
        }
        if (! $this->canManage($makerId, $targetId)) {
            return null;
        }

        return $this->powersFor($makerId, $targetId)[$key] ?? null;
    }

    // A developer's gift or limit counts as a developer's whatever mode they are in now.
    private function isDeveloperMaker(int $makerId, int $targetId): bool
    {
        $maker = $this->employees[$makerId] ?? null;

        return $maker !== null && (bool) $maker->is_developer && $makerId !== $targetId
            && isset($this->employees[$targetId]) && self::isActive($maker);
    }

    // Same role tier: two admins, two executives. A developer maker is never an equal.
    private function areEquals(int $makerId, int $targetId): bool
    {
        $maker = $this->employees[$makerId] ?? null;
        $target = $this->employees[$targetId] ?? null;
        if ($maker === null || $target === null || $this->isDeveloperMaker($makerId, $targetId)) {
            return false;
        }

        return $this->actingTier($makerId) === self::tier($target);
    }

    /**
     * Every rule in the catalog at its widest, for the viewer in Developer mode.
     *
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>
     */
    private function everything(): array
    {
        $rules = [];
        foreach ($this->catalog as $key => $row) {
            $rules[$key] = ['reach' => (bool) $row->supports_reach ? 'all' : null, 'executives' => true, 'fromRole' => true, 'givenBy' => []];
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
     * @param  array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>  $rules
     * @return array<string, array{reach: ?string, executives: bool, fromRole: bool, givenBy: list<int>}>
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
