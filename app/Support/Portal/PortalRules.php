<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The rules a person may use right now: what they effectively hold (PortalAccessGraph, every rule
 * in Developer mode) minus what they switched off for themselves on the Access page, at no more
 * than the reach they narrowed themselves to. A power is off while its own switch or any page above
 * it is off. This is what enforcement asks, and what the session tells the portal so it can hide
 * what cannot be opened.
 */
final class PortalRules
{
    public const REFUSAL = 'You do not have access to this page.';

    // A rule's ignore power is the rule's key with this suffix: holding it skips every list on the rule.
    public const UNLISTED = 'unlisted';

    // Lets All Logs show what developers did; nobody gets it by being given All Logs.
    public const DEVELOPERS_ACTIVITY = 'administration.logs.developers';

    // Sent with a write the developer confirmed after being told it goes past their Default powers.
    public const CONFIRM_HEADER = 'X-Portal-Developer-Confirmed';

    // The 428 body's code, so the portal can tell this apart from any other precondition.
    public const CONFIRM_CODE = 'developer_confirmation';

    private const ATTRIBUTE = 'portalActiveRules';

    private const DEFAULT_ATTRIBUTE = 'portalDefaultRules';

    private const BEYOND_ATTRIBUTE = 'portalBeyondDefault';

    /**
     * Active rule => reach, in catalog order. Worked out once per request.
     *
     * @return array<string, ?string>
     */
    public static function active(Employee $employee, Request $request): array
    {
        // A guest answered from its share alone: the granted pages at all-reach, nothing else.
        $guest = self::guest($request);
        if ($guest !== null) {
            return self::guestRules($guest);
        }

        $cacheKey = $employee->getKey().':'.(PortalDeveloperMode::isOn($employee, $request) ? 'dev' : 'default');
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if (is_array($cached) && ($cached['key'] ?? null) === $cacheKey) {
            return $cached['rules'];
        }

        $rules = self::evaluate(PortalAccessGraph::forViewer($employee, $request), $employee);
        $request->attributes->set(self::ATTRIBUTE, ['key' => $cacheKey, 'rules' => $rules]);

        return $rules;
    }

    /**
     * What the person may use in Default, whatever mode they are in right now.
     *
     * @return array<string, ?string>
     */
    public static function activeInDefault(Employee $employee, Request $request): array
    {
        $guest = self::guest($request);
        if ($guest !== null) {
            return self::guestRules($guest);
        }
        if (! PortalDeveloperMode::isOn($employee, $request)) {
            return self::active($employee, $request);
        }
        $cached = $request->attributes->get(self::DEFAULT_ATTRIBUTE);
        if (is_array($cached) && ($cached['id'] ?? null) === (int) $employee->getKey()) {
            return $cached['rules'];
        }

        $rules = self::evaluate(PortalAccessGraph::load(), $employee);
        $request->attributes->set(self::DEFAULT_ATTRIBUTE, ['id' => (int) $employee->getKey(), 'rules' => $rules]);

        return $rules;
    }

    /**
     * @return array<string, ?string>
     */
    private static function evaluate(PortalAccessGraph $graph, Employee $employee): array
    {
        $catalog = $graph->catalog();
        $off = self::switchedOff($employee);
        $narrowed = self::selfReach($employee);
        $rules = [];
        foreach ($graph->effective((int) $employee->getKey()) as $key => $rule) {
            if (self::isSwitchedOn($catalog, $off, $key)) {
                $rules[$key] = self::narrower($rule['reach'], $narrowed[$key] ?? null);
            }
        }

        return $rules;
    }

    public static function holds(Employee $employee, Request $request, string $key): bool
    {
        return array_key_exists($key, self::active($employee, $request));
    }

    // A reach rule's reach, or null when it is not held right now; a rule carrying none reads as own, the narrowest.
    public static function reach(Employee $employee, Request $request, string $key): ?string
    {
        $rules = self::active($employee, $request);

        return array_key_exists($key, $rules) ? ($rules[$key] ?? 'own') : null;
    }

    /**
     * Whether a reach lets the actor work on the owner's record: all is everyone, department their
     * own department, and own (or team, until the hierarchy is read) only themselves. Executives are
     * managed from their own portal, so their rows are in nobody's reach unless $withExecutives; a
     * record whose owner is unknown has no department, so only an all reach sees it.
     */
    public static function covers(?string $reach, Employee $actor, ?Employee $owner, bool $withExecutives = false): bool
    {
        if ($reach === null || (! $withExecutives && $owner !== null && PortalRole::isExecutive($owner->role, $owner->role_level))) {
            return false;
        }
        if ($reach === 'all') {
            return true;
        }
        if ($owner === null) {
            return false;
        }
        if ($reach === 'department') {
            $department = PortalPreview::department($actor->department);

            return $department !== '' && $department === PortalPreview::department($owner->department);
        }

        return (int) $owner->getKey() === (int) $actor->getKey();
    }

    /**
     * Whether the white and black lists set on this person's rule let them see someone: anyone's
     * black list hides a person, and every white list must name them. The rule's ignore power
     * (<key>.unlisted), which Developer mode holds, skips every list.
     *
     * @return callable(Employee): bool
     */
    public static function listed(Employee $employee, Request $request, string $key): callable
    {
        // A guest's share carries no lists; it sees everyone the page's reach shows.
        if (self::guest($request) !== null) {
            return static fn (): bool => true;
        }
        if (self::holds($employee, $request, $key.'.'.self::UNLISTED)) {
            return static fn (): bool => true;
        }
        $lists = PortalAccessGraph::forViewer($employee, $request)->listsOn((int) $employee->getKey(), $key);

        return static function (Employee $person) use ($lists): bool {
            $named = static fn (array $list): bool => in_array((int) $person->getKey(), $list['ids'], true);
            foreach ($lists as $list) {
                if ($list['mode'] === 'block' ? $named($list) : ! $named($list)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * The active people a rule lets this person open right now: whoever its reach covers and the
     * lists leave in, and always themselves. Never an executive, themselves included: the portal
     * keeps no executive's records on these pages.
     *
     * @return list<Employee>
     */
    public static function people(Employee $actor, Request $request, string $key): array
    {
        $reach = self::reach($actor, $request, $key);
        $listed = self::listed($actor, $request, $key);

        return Employee::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'department', 'role', 'role_level'])
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Employee $person): bool => ! PortalRole::isExecutive($person->role, $person->role_level)
                && ($person->is($actor) || (self::covers($reach, $actor, $person) && $listed($person))))
            ->values()
            ->all();
    }

    /**
     * Everyone who holds every one of the rules right now, as their role: nobody's Developer mode
     * counts outside their own requests. Each with the reach of each rule, for alerts that go to
     * whoever may act on a record.
     *
     * @param  list<string>  $keys
     * @return list<array{employee: Employee, reaches: array<string, ?string>}>
     */
    public static function holders(array $keys): array
    {
        $graph = PortalAccessGraph::load();
        $catalog = $graph->catalog();
        $connection = DB::connection('portal');
        $off = [];
        foreach ($connection->table('access_toggles')->whereIn('permission_key', self::withAncestors($graph, $keys))->get() as $row) {
            $off[(int) $row->employee_id][(string) $row->permission_key] = true;
        }
        $narrowed = [];
        foreach ($connection->table('access_self_reach')->whereIn('permission_key', $keys)->get() as $row) {
            $narrowed[(int) $row->employee_id][(string) $row->permission_key] = (string) $row->reach;
        }

        $holders = [];
        foreach ($graph->employees() as $id => $employee) {
            $effective = $graph->effective($id);
            $reaches = [];
            foreach ($keys as $key) {
                if (! isset($effective[$key]) || ! self::isSwitchedOn($catalog, $off[$id] ?? [], $key)) {
                    continue 2;
                }
                $reaches[$key] = self::narrower($effective[$key]['reach'], $narrowed[$id][$key] ?? null);
            }
            $holders[] = ['employee' => $employee, 'reaches' => $reaches];
        }

        return $holders;
    }

    /**
     * Refuses unless they hold at least one of the rules: a plain 403, not a security alert, since
     * switching a power off yourself lands here too.
     */
    public static function require(Employee $employee, Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            if (self::holds($employee, $request, $key)) {
                return;
            }
        }

        abort(403, self::REFUSAL);
    }

    /**
     * The rule check for someone's own work (their reports, requests, calendar). Within what Default
     * allows, it is theirs by name even in Developer mode. Past it (a boss limited it, or their role
     * lacks it) Developer mode still lets it through, but a write waits for their confirmation and is
     * logged as a developer's, since that is what went past the limit. A power they switched off
     * themselves stays off in both modes, so it never gets this far.
     */
    public static function requireOwn(Employee $employee, Request $request, string ...$keys): void
    {
        self::require($employee, $request, ...$keys);
        $inDefault = self::activeInDefault($employee, $request);
        $beyond = true;
        foreach ($keys as $key) {
            if (array_key_exists($key, $inDefault)) {
                $beyond = false;
                break;
            }
        }
        $request->attributes->set(self::BEYOND_ATTRIBUTE, $beyond);

        if ($beyond && ! $request->isMethodSafe() && $request->header(self::CONFIRM_HEADER) !== '1') {
            throw new HttpResponseException(response()->json([
                'message' => self::confirmation($keys),
                'code' => self::CONFIRM_CODE,
            ], 428));
        }
    }

    // The guest a shared-page token stands for, when this request carries one.
    private static function guest(Request $request): ?PortalGuest
    {
        $guest = $request->attributes->get('portalShare');

        return $guest instanceof PortalGuest ? $guest : null;
    }

    /**
     * A guest's rules: every granted page at the reach the share handed it out at, all for a page
     * that carries none, since a guest has no department or person of their own.
     *
     * @return array<string, ?string>
     */
    private static function guestRules(PortalGuest $guest): array
    {
        $rules = [];
        foreach ($guest->rules as $key => $reach) {
            $rules[$key] = $reach ?? 'all';
        }

        return $rules;
    }

    // An ignore power, or See developers' activity: given only on its own, never as a tab of its page.
    public static function givenAlone(string $key): bool
    {
        return str_ends_with($key, '.'.self::UNLISTED) || $key === self::DEVELOPERS_ACTIVITY;
    }

    // False only for someone's own work within Default; everything else a developer does in Developer mode is a developer's act.
    public static function isDevelopersAct(Request $request): bool
    {
        return $request->attributes->get(self::BEYOND_ATTRIBUTE, true) !== false;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function confirmation(array $keys): string
    {
        // The whole path, since a bare label like "Restore" does not say which power it is.
        $label = PortalAccessGraph::load()->path($keys[0] ?? '');

        return 'You cannot use "'.$label.'" in Default: someone limited it, or your role does not include it. '
            .'Developer mode can still do it, but the log will read "A developer" instead of your name.';
    }

    /**
     * How far the person narrowed each reach rule for themselves.
     *
     * @return array<string, string>
     */
    public static function selfReach(Employee $employee): array
    {
        return DB::connection('portal')->table('access_self_reach')->where('employee_id', $employee->getKey())->pluck('reach', 'permission_key')->all();
    }

    // Their own narrowing only ever narrows: a wider or missing choice leaves what they hold.
    public static function narrower(?string $held, ?string $chosen): ?string
    {
        if ($chosen === null || $held === null) {
            return $held;
        }

        return PortalAccessGraph::reachRank($chosen) < PortalAccessGraph::reachRank($held) ? $chosen : $held;
    }

    /**
     * The rules the person switched off for themselves.
     *
     * @return array<string, true>
     */
    public static function switchedOff(Employee $employee): array
    {
        return array_fill_keys(
            DB::connection('portal')->table('access_toggles')->where('employee_id', $employee->getKey())->pluck('permission_key')->all(),
            true,
        );
    }

    /**
     * The rules off because the person switched them off themselves: their own toggles plus every
     * page under them, since switching a section off takes its pages with it. The portal names the
     * page a refused link was aiming at, and only this set earns the "you switched this off" notice.
     *
     * @return list<string>
     */
    public static function switchedOffEffective(Employee $employee): array
    {
        $off = array_keys(self::switchedOff($employee));
        if ($off === []) {
            return [];
        }

        $children = [];
        foreach (DB::connection('portal')->table('access_permissions')->select('permission_key', 'parent_key')->get() as $row) {
            if ($row->parent_key !== null) {
                $children[$row->parent_key][] = $row->permission_key;
            }
        }

        $all = $off;
        for ($queue = $off; $queue !== [];) {
            $key = array_pop($queue);
            foreach ($children[$key] ?? [] as $child) {
                if (! in_array($child, $all, true)) {
                    $all[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $all;
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function withAncestors(PortalAccessGraph $graph, array $keys): array
    {
        $all = [];
        foreach ($keys as $key) {
            array_push($all, ...[...$graph->ancestors($key), $key]);
        }

        return array_values(array_unique($all));
    }

    /**
     * On only while its own switch and every ancestor's switch are on.
     *
     * @param  array<string, object>  $catalog
     * @param  array<string, true>  $off
     */
    public static function isSwitchedOn(array $catalog, array $off, string $key): bool
    {
        for ($current = $key; $current !== null; $current = $catalog[$current]->parent_key ?? null) {
            if (isset($off[$current])) {
                return false;
            }
        }

        return true;
    }
}
