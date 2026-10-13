<?php

namespace App\Support\Portal;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;

/**
 * The scope tree (WBS), one per department (2026-10-13, user): four tiers -- processes 100, procedures
 * 100-01, activities 100-01-01, tasks 100-01-01-01 -- in their four tables, joined by code prefix. Every
 * row from Airtable has no department and is Engineering's; a tree takes its department from its top
 * scope. Codes are typed by whoever edits Manage / References, and stay unique across departments,
 * because a project's scopes (projects_wbs_scopes) hold only the code.
 */
final class PortalScopeTree
{
    private const DEFAULT_DEPARTMENT = 'Engineering';

    // Depth => the table that tier lives in, and its name column (tasks call theirs task_name).
    private const TIERS = [
        1 => ['project_scope_t1_processes', 'name'],
        2 => ['project_scope_t2_procedures', 'name'],
        3 => ['project_scope_t3_activities', 'name'],
        4 => ['project_scope_t4_tasks', 'task_name'],
    ];

    private const CODE_SHAPE = '/^\d{3}(-\d{2}){0,3}$/';

    /**
     * Every department's top scopes, each with its sub-scopes, in code order.
     *
     * @return list<array{code: string, name: string, department: string, children: list<array<string, mixed>>}>
     */
    public function roots(): array
    {
        $nodes = [];
        foreach (self::TIERS as $depth => [$table, $nameColumn]) {
            $columns = $depth === 4 ? ['wbs_code', $nameColumn] : ['wbs_code', $nameColumn, 'department'];
            foreach ($this->connection()->table($table)->get($columns) as $row) {
                $code = self::code($row->wbs_code);
                if (preg_match(self::CODE_SHAPE, $code) !== 1) {
                    continue;
                }
                $department = trim((string) ($row->department ?? ''));
                $nodes[$code] = [
                    'code' => $code,
                    'name' => self::plain($row->{$nameColumn}),
                    'department' => $department === '' ? self::DEFAULT_DEPARTMENT : $department,
                    'children' => [],
                ];
            }
        }
        ksort($nodes, SORT_STRING);

        // Deepest first, so each node is whole before it joins its parent; a node without one stays on top.
        $codes = array_keys($nodes);
        usort($codes, static fn (string $a, string $b): int => substr_count($b, '-') <=> substr_count($a, '-') ?: strcmp($a, $b));
        foreach ($codes as $code) {
            $parent = self::parentOf($code);
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = $nodes[$code];
                unset($nodes[$code]);
            }
        }
        $roots = array_values($nodes);
        foreach ($roots as &$root) {
            $root = self::settle($root, $root['department'], $root['code']);
        }

        return $roots;
    }

    /**
     * Every code in one department's tree, for checking what a project of that department may hold.
     *
     * @return list<string>
     */
    public function codesFor(mixed $department): array
    {
        $key = PortalPreview::department($department);
        $codes = [];
        foreach ($this->roots() as $root) {
            if (PortalPreview::department($root['department']) === $key) {
                array_push($codes, ...self::flatten($root));
            }
        }

        return $codes;
    }

    public function add(string $department, string $code, string $name): void
    {
        if (preg_match(self::CODE_SHAPE, $code) !== 1) {
            abort(422, 'Write the code as 100, 100-01, 100-01-01 or 100-01-01-01.');
        }
        $roots = $this->roots();
        $codes = [];
        foreach ($roots as $root) {
            array_push($codes, ...self::flatten($root));
        }
        if (in_array($code, $codes, true)) {
            abort(422, 'That scope code is already used.');
        }
        $parent = self::parentOf($code);
        if ($parent !== null && ! in_array($parent, $this->codesFor($department), true)) {
            abort(422, 'Add it under a scope of the same department: '.$parent.' is not one.');
        }
        $depth = substr_count($code, '-') + 1;
        [$table, $nameColumn] = self::TIERS[$depth];
        $row = ['wbs_code' => $code, $nameColumn => $name];
        if ($depth < 4) {
            $row['department'] = $department;
        }
        $this->connection()->table($table)->insert($row);
    }

    public function rename(string $code, string $name): void
    {
        [$table, $nameColumn] = $this->tierOf($code);
        $this->rows($table, $code)->update([$nameColumn => $name]);
    }

    // A scope with sub-scopes, or one a project holds, stays until those are cleared (2026-10-13, user).
    public function remove(string $code): void
    {
        [$table] = $this->tierOf($code);
        foreach (self::TIERS as [$tier]) {
            if ($this->connection()->table($tier)->whereRaw('TRIM(wbs_code) LIKE ?', [$code.'-%'])->exists()) {
                abort(422, 'Remove its sub-scopes first.');
            }
        }
        if ($this->connection()->table('projects_wbs_scopes')->where('wbs_code', $code)->exists()) {
            abort(422, 'Projects use this scope. Take it off them first.');
        }
        $this->rows($table, $code)->delete();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function tierOf(string $code): array
    {
        $tier = self::TIERS[substr_count($code, '-') + 1] ?? null;
        if (preg_match(self::CODE_SHAPE, $code) !== 1 || $tier === null || ! $this->rows($tier[0], $code)->exists()) {
            abort(404, 'That scope was not found.');
        }

        return $tier;
    }

    private function rows(string $table, string $code): Builder
    {
        return $this->connection()->table($table)->whereRaw('TRIM(wbs_code) = ?', [$code]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    // Every node takes its tree's department; one Airtable left unnamed reads as its parent, as it always has.
    private static function settle(array $node, string $department, string $parentName): array
    {
        $node['department'] = $department;
        if ($node['name'] === '') {
            $node['name'] = $parentName;
        }
        $node['children'] = array_map(static fn (array $child): array => self::settle($child, $department, $node['name']), $node['children']);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function flatten(array $node): array
    {
        $codes = [$node['code']];
        foreach ($node['children'] as $child) {
            array_push($codes, ...self::flatten($child));
        }

        return $codes;
    }

    // Airtable holds one task code with its number missing ('200-03-02-'), which reads as the first task.
    private static function code(mixed $raw): string
    {
        $code = trim((string) $raw);

        return str_ends_with($code, '-') ? $code.'01' : $code;
    }

    // Airtable's rich text marks bold with ** and escapes an asterisk; the tree shows the words alone.
    private static function plain(mixed $raw): string
    {
        $text = str_replace(['**', '\*'], ['', '*'], (string) $raw);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function parentOf(string $code): ?string
    {
        $cut = strrpos($code, '-');

        return $cut === false ? null : substr($code, 0, $cut);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
