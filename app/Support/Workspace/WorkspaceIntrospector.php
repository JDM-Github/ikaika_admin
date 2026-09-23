<?php

namespace App\Support\Workspace;

use App\Support\ProductRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Workspace introspection: what tables and columns a product database actually has.
 *
 * There is no Eloquent model for 64 of the 77 tables, so everything here reads
 * information_schema through the product connection and returns plain arrays. The
 * portal database enforces 52 foreign keys; core and the estimator enforce none,
 * because the estimator dump used Postgres inline REFERENCES which MySQL discards --
 * so link resolution falls back to matching a junction name against the real table
 * list rather than guessing a plural.
 */
final class WorkspaceIntrospector
{
    private const CACHE_PREFIX = 'workspace:introspect:';

    /**
     * Columns probed per statement. A 93-column table would otherwise build one
     * SELECT with several hundred aggregate expressions.
     */
    private const PROBE_CHUNK = 12;

    public function connection(string $product): Connection
    {
        $config = ProductRegistry::get($product);

        return DB::connection($config['connection']);
    }

    public function database(string $product): string
    {
        return (string) (ProductRegistry::get($product)['database'] ?? '');
    }

    /**
     * Every base table in the product database, in name order.
     *
     * @return list<string>
     */
    public function tableNames(string $product): array
    {
        return $this->remember($product, 'tables', function () use ($product): array {
            $rows = $this->connection($product)
                ->select(
                    'select TABLE_NAME as name from information_schema.TABLES
                     where TABLE_SCHEMA = ? and TABLE_TYPE = ? order by TABLE_NAME',
                    [$this->database($product), 'BASE TABLE'],
                );

            $names = [];
            foreach ($rows as $row) {
                $names[] = (string) $row->name;
            }

            return $names;
        });
    }

    /**
     * Column descriptors keyed by table name, in declared order.
     *
     * @return array<string, list<array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, nullable: bool, key: string, extra: string}>>
     */
    public function columns(string $product): array
    {
        return $this->remember($product, 'columns', function () use ($product): array {
            $rows = $this->connection($product)
                ->select(
                    'select TABLE_NAME as t, COLUMN_NAME as name, DATA_TYPE as data_type,
                            COLUMN_TYPE as column_type, CHARACTER_MAXIMUM_LENGTH as max_length,
                            NUMERIC_SCALE as numeric_scale, IS_NULLABLE as nullable,
                            COLUMN_KEY as col_key, EXTRA as extra
                     from information_schema.COLUMNS
                     where TABLE_SCHEMA = ? order by TABLE_NAME, ORDINAL_POSITION',
                    [$this->database($product)],
                );

            $byTable = [];
            foreach ($rows as $row) {
                $byTable[(string) $row->t][] = [
                    'name' => (string) $row->name,
                    'data_type' => (string) $row->data_type,
                    'column_type' => (string) $row->column_type,
                    'max_length' => $row->max_length === null ? null : (int) $row->max_length,
                    'numeric_scale' => $row->numeric_scale === null ? null : (int) $row->numeric_scale,
                    'nullable' => strtoupper((string) $row->nullable) === 'YES',
                    'key' => (string) $row->col_key,
                    'extra' => (string) $row->extra,
                ];
            }

            return $byTable;
        });
    }

    /**
     * Primary-key column names keyed by table.
     *
     * @return array<string, list<string>>
     */
    public function primaryKeys(string $product): array
    {
        $keys = [];
        foreach ($this->columns($product) as $table => $columns) {
            foreach ($columns as $column) {
                if ($column['key'] === 'PRI') {
                    $keys[$table][] = $column['name'];
                }
            }
        }

        return $keys;
    }

    /**
     * Declared foreign keys: table => column => referenced table. Empty for core and
     * the estimator, which is why linkTarget falls through to name matching.
     *
     * @return array<string, array<string, string>>
     */
    public function foreignKeys(string $product): array
    {
        return $this->remember($product, 'fks', function () use ($product): array {
            $rows = $this->connection($product)
                ->select(
                    'select TABLE_NAME as t, COLUMN_NAME as c, REFERENCED_TABLE_NAME as r
                     from information_schema.KEY_COLUMN_USAGE
                     where TABLE_SCHEMA = ? and REFERENCED_TABLE_NAME is not null',
                    [$this->database($product)],
                );

            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row->t][(string) $row->c] = (string) $row->r;
            }

            return $map;
        });
    }

    /**
     * Junction tables, which are Airtable link fields rather than tables of their own.
     *
     * Shape: no auto-increment surrogate, the primary key covers every column, and
     * exactly two integer columns ending in _id. A third primary-key column that is
     * not an integer is a qualifier -- employees_projects.role_on_project is what
     * Airtable displayed as three separate link fields.
     *
     * @return array<string, array{left: string, right: string, left_column: string, right_column: string, qualifier: ?string}>
     */
    public function junctions(string $product): array
    {
        $tables = $this->tableNames($product);
        $columns = $this->columns($product);
        $primary = $this->primaryKeys($product);
        $foreign = $this->foreignKeys($product);

        $junctions = [];
        foreach ($tables as $table) {
            $found = $this->asJunction($table, $columns[$table] ?? [], $primary[$table] ?? [], $tables, $foreign[$table] ?? []);
            if ($found !== null) {
                $junctions[$table] = $found;
            }
        }

        return $junctions;
    }

    /**
     * @param  list<array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, nullable: bool, key: string, extra: string}>  $columns
     * @param  list<string>  $primary
     * @param  list<string>  $tables
     * @param  array<string, string>  $foreign
     * @return array{left: string, right: string, left_column: string, right_column: string, qualifier: ?string}|null
     */
    private function asJunction(string $table, array $columns, array $primary, array $tables, array $foreign): ?array
    {
        if ($columns === [] || $primary === [] || count($primary) !== count($columns)) {
            return null;
        }

        $links = [];
        $qualifier = null;
        foreach ($columns as $column) {
            if (str_contains(strtolower($column['extra']), 'auto_increment')) {
                return null;
            }

            $isIntegerId = str_ends_with($column['name'], '_id')
                && in_array(strtolower($column['data_type']), ['int', 'integer', 'bigint', 'smallint', 'mediumint'], true);

            if ($isIntegerId) {
                $links[] = $column['name'];

                continue;
            }

            if ($qualifier !== null) {
                return null;
            }

            $qualifier = $column['name'];
        }

        if (count($links) !== 2) {
            return null;
        }

        $left = $this->linkTarget($table, $links[0], $tables, $foreign);
        $right = $this->linkTarget($table, $links[1], $tables, $foreign);

        if ($left === null || $right === null) {
            return null;
        }

        return [
            'left' => $left,
            'right' => $right,
            'left_column' => $links[0],
            'right_column' => $links[1],
            'qualifier' => $qualifier,
        ];
    }

    /**
     * Resolve the table an _id column points at: declared foreign key first, then the
     * two real table names the junction is named after, then a naive plural.
     *
     * @param  list<string>  $tables
     * @param  array<string, string>  $foreign
     */
    public function linkTarget(string $junction, string $column, array $tables, array $foreign): ?string
    {
        if (isset($foreign[$column])) {
            return $foreign[$column];
        }

        $stem = substr($column, 0, -3);

        foreach ($this->namedHalves($junction, $tables) as $half) {
            if ($half === $stem || str_starts_with($half, $stem) || $this->singular($half) === $stem) {
                return $half;
            }
        }

        foreach ([$stem.'s', $stem.'es', $stem] as $candidate) {
            if (in_array($candidate, $tables, true)) {
                return $candidate;
            }
        }

        foreach ($tables as $candidate) {
            if ($this->singular($candidate) === $stem) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Depluralise each segment, so earn_code_v2_id reaches earn_codes_v2. A trailing
     * s on a middle segment is what defeats a whole-name plural rule.
     */
    private function singular(string $table): string
    {
        $parts = [];
        foreach (explode('_', $table) as $part) {
            $parts[] = strlen($part) > 1 && str_ends_with($part, 's') ? substr($part, 0, -1) : $part;
        }

        return implode('_', $parts);
    }

    /**
     * One-to-many links: a child table carrying exactly one resolvable link back to a
     * parent. bim_form_elements_included is an Airtable multipleSelects field turned
     * into rows, so the parent needs it as a chip column although it is no junction.
     *
     * @return array<string, array{parent: string, column: string, label_column: ?string}>
     */
    public function childLinks(string $product): array
    {
        $tables = $this->tableNames($product);
        $columns = $this->columns($product);
        $junctions = $this->junctions($product);
        $foreign = $this->foreignKeys($product);

        $children = [];
        foreach ($tables as $table) {
            if (isset($junctions[$table])) {
                continue;
            }

            $found = null;
            $ambiguous = false;
            foreach ($columns[$table] ?? [] as $column) {
                if (! $this->isIntegerId($column)) {
                    continue;
                }

                $parent = $this->linkTarget($table, $column['name'], $tables, $foreign[$table] ?? []);
                if ($parent === null || $parent === $table || isset($junctions[$parent])) {
                    continue;
                }

                if ($found !== null) {
                    $ambiguous = true;

                    break;
                }

                $found = ['parent' => $parent, 'column' => $column['name'], 'label_column' => null];
            }

            if ($ambiguous || $found === null) {
                continue;
            }

            $found['label_column'] = $this->firstDescriptiveColumn($columns[$table] ?? [], $found['column']);
            $children[$table] = $found;
        }

        return $children;
    }

    /**
     * @param  array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, nullable: bool, key: string, extra: string}  $column
     */
    private function isIntegerId(array $column): bool
    {
        return str_ends_with($column['name'], '_id')
            && $column['name'] !== 'airtable_record_id'
            && ! str_contains(strtolower($column['extra']), 'auto_increment')
            && in_array(strtolower($column['data_type']), ['int', 'integer', 'bigint', 'smallint', 'mediumint'], true);
    }

    /**
     * What a chip for one child row should read as: its first text column that is not
     * the link itself.
     *
     * @param  list<array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, nullable: bool, key: string, extra: string}>  $columns
     */
    private function firstDescriptiveColumn(array $columns, string $skip): ?string
    {
        foreach ($columns as $column) {
            if ($column['name'] === $skip || $column['name'] === 'airtable_record_id') {
                continue;
            }
            if (str_contains(strtolower($column['extra']), 'auto_increment')) {
                continue;
            }
            if (in_array(strtolower($column['data_type']), ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * A junction is named after the two tables it joins, so splitting its name against
     * the real table list resolves earn_code_v2_id to earn_codes_v2 without a
     * pluralisation rule that would have produced earn_code_v2s.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function namedHalves(string $junction, array $tables): array
    {
        $parts = explode('_', $junction);
        for ($split = 1; $split < count($parts); $split++) {
            $left = implode('_', array_slice($parts, 0, $split));
            $right = implode('_', array_slice($parts, $split));

            if (in_array($left, $tables, true) && in_array($right, $tables, true)) {
                return [$left, $right];
            }
        }

        return [];
    }

    /**
     * COUNT(*) per table. information_schema.TABLE_ROWS is an InnoDB estimate and the
     * grid footer has to be exact, so this counts for real and caches the result.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    public function rowCounts(string $product, array $tables): array
    {
        sort($tables);

        return $this->remember($product, 'counts:'.md5(implode(',', $tables)), function () use ($product, $tables): array {
            $connection = $this->connection($product);
            $counts = [];

            foreach (array_chunk($tables, 20) as $chunk) {
                $selects = [];
                foreach ($chunk as $table) {
                    $selects[] = 'select '.$connection->getPdo()->quote($table).' as t, count(*) as n from '.$this->quote($table);
                }

                foreach ($connection->select(implode(' union all ', $selects)) as $row) {
                    $counts[(string) $row->t] = (int) $row->n;
                }
            }

            return $counts;
        });
    }

    /**
     * Shape probe for the string columns of one table: how many values are emails or
     * urls, how long the longest is, how many distinct trimmed values there are.
     * Trimming matters -- bank_swift_code holds both BOPIPHMM and BOPIPHMM with a
     * trailing space, which would otherwise become two select options.
     *
     * @param  list<string>  $columns
     * @return array<string, array{nonnull: int, distinct: int, maxlen: int, urls: int, emails: int, multiline: int, jsonish: int}>
     */
    public function probe(string $product, string $table, array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        sort($columns);

        return $this->remember($product, 'probe:'.$table.':'.md5(implode(',', $columns)), function () use ($product, $table, $columns): array {
            $connection = $this->connection($product);
            $probes = [];

            foreach (array_chunk($columns, self::PROBE_CHUNK) as $index => $chunk) {
                $selects = [];
                foreach ($chunk as $position => $column) {
                    $quoted = $this->quote($column);
                    $alias = 'c'.$index.'_'.$position;
                    $selects[] = 'count('.$quoted.') as '.$alias.'_nonnull';
                    $selects[] = 'count(distinct trim('.$quoted.')) as '.$alias.'_distinct';
                    $selects[] = 'coalesce(max(char_length('.$quoted.')), 0) as '.$alias.'_maxlen';
                    $selects[] = "sum(case when {$quoted} regexp '^https?://' then 1 else 0 end) as {$alias}_urls";
                    $selects[] = "sum(case when {$quoted} regexp '^[^@[:space:]]+@[^@[:space:]]+[.][A-Za-z]{2,}$' then 1 else 0 end) as {$alias}_emails";
                    $selects[] = "sum(case when {$quoted} like '%\n%' then 1 else 0 end) as {$alias}_multiline";
                    $selects[] = "sum(case when trim({$quoted}) like '{%' or trim({$quoted}) like '[%' then 1 else 0 end) as {$alias}_jsonish";
                }

                $row = $connection->selectOne('select '.implode(', ', $selects).' from '.$this->quote($table));
                if ($row === null) {
                    continue;
                }

                foreach ($chunk as $position => $column) {
                    $alias = 'c'.$index.'_'.$position;
                    $probes[$column] = [
                        'nonnull' => (int) $row->{$alias.'_nonnull'},
                        'distinct' => (int) $row->{$alias.'_distinct'},
                        'maxlen' => (int) $row->{$alias.'_maxlen'},
                        'urls' => (int) $row->{$alias.'_urls'},
                        'emails' => (int) $row->{$alias.'_emails'},
                        'multiline' => (int) $row->{$alias.'_multiline'},
                        'jsonish' => (int) $row->{$alias.'_jsonish'},
                    ];
                }
            }

            return $probes;
        });
    }

    /**
     * Distinct trimmed values for a select column, smallest palette first.
     *
     * @return list<string>
     */
    public function options(string $product, string $table, string $column, int $limit): array
    {
        return $this->remember($product, 'options:'.$table.':'.$column, function () use ($product, $table, $column, $limit): array {
            $rows = $this->connection($product)->select(
                'select trim('.$this->quote($column).') as v, count(*) as n from '.$this->quote($table).
                ' where '.$this->quote($column).' is not null and trim('.$this->quote($column).") <> ''".
                ' group by v order by n desc, v asc limit '.$limit,
            );

            $values = [];
            foreach ($rows as $row) {
                $values[] = (string) $row->v;
            }

            return $values;
        });
    }

    /**
     * The auto-increment column a record is addressed by, or null when the table has
     * none. Every Airtable-converted table has one; the junctions deliberately do not.
     */
    public function surrogateKey(string $product, string $table): ?string
    {
        foreach ($this->columns($product)[$table] ?? [] as $column) {
            if (str_contains(strtolower($column['extra']), 'auto_increment')) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * Backtick-quote an identifier. project_scope_t3_activities.procedure and
     * .level are reserved words, so every generated identifier goes through this.
     */
    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * Bump the version every cached key is built from. Forgetting keys one by one is
     * not possible here -- a probe key carries a hash of its column list -- and the
     * file driver has no tags, so the version is the only handle there is.
     */
    public static function flush(): void
    {
        Cache::forever(self::CACHE_PREFIX.'version', self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::CACHE_PREFIX.'version', 1);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function remember(string $product, string $key, callable $build)
    {
        $ttl = (int) config('workspace.cache_ttl', 300);

        return Cache::remember(self::CACHE_PREFIX.self::version().':'.$product.':'.$key, $ttl, $build);
    }
}
