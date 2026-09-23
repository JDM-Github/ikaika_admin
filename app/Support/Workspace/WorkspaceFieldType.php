<?php

namespace App\Support\Workspace;

/**
 * Workspace field types: the renderings a dense grid needs, and how to pick one.
 *
 * COLUMN_COMMENT is empty in all three databases and the estimator carries no foreign
 * keys, so the singleSelect annotations in the checked-in SQL never reached MySQL.
 * Type therefore comes from the declared column shape first and a cheap data probe
 * second; the probe only ever promotes a text column to email, url, select or long
 * text, and never overrides DATA_TYPE, because coercing clients.client_id would
 * destroy its leading zeros.
 */
final class WorkspaceFieldType
{
    public const ID = 'id';

    public const EXTERNAL = 'external';

    public const TITLE = 'title';

    public const TEXT = 'text';

    public const LONGTEXT = 'longtext';

    public const INTEGER = 'integer';

    public const DECIMAL = 'decimal';

    public const PERCENT = 'percent';

    public const BOOLEAN = 'boolean';

    public const SELECT = 'select';

    public const DATE = 'date';

    public const DATETIME = 'datetime';

    public const URL = 'url';

    public const EMAIL = 'email';

    public const JSON = 'json';

    public const LINK = 'link';

    public const REFERENCE = 'reference';

    /**
     * Grid column width in pixels, by type. Deterministic so a table looks the same
     * for everyone -- there are no saved per-user widths to drift out of sync.
     *
     * @var array<string, int>
     */
    private const WIDTHS = [
        self::ID => 72,
        self::EXTERNAL => 132,
        self::TITLE => 240,
        self::TEXT => 180,
        self::LONGTEXT => 280,
        self::INTEGER => 96,
        self::DECIMAL => 96,
        self::PERCENT => 110,
        self::BOOLEAN => 64,
        self::SELECT => 150,
        self::DATE => 112,
        self::DATETIME => 148,
        self::URL => 200,
        self::EMAIL => 200,
        self::JSON => 200,
        self::LINK => 200,
        self::REFERENCE => 180,
    ];

    /**
     * Sort order of the columns themselves. Identity and title first, audit trail last,
     * so the useful half of a 93-column table is on screen before the first scroll.
     *
     * @var array<string, int>
     */
    private const RANK = [
        self::ID => 0,
        self::TITLE => 1,
        self::REFERENCE => 2,
        self::LINK => 3,
        self::SELECT => 4,
        self::DATE => 5,
        self::DATETIME => 6,
        self::INTEGER => 7,
        self::DECIMAL => 7,
        self::PERCENT => 7,
        self::BOOLEAN => 8,
        self::EMAIL => 9,
        self::URL => 9,
        self::TEXT => 10,
        self::JSON => 11,
        self::LONGTEXT => 12,
        self::EXTERNAL => 13,
    ];

    /**
     * A select palette is capped here; past it the column is free text, not a choice.
     */
    private const MAX_SELECT_OPTIONS = 25;

    /**
     * Longer than this, or carrying a newline, and the cell needs room to breathe.
     * DATA_TYPE is useless for this -- most TEXT columns here are under 60 characters.
     */
    private const LONGTEXT_LENGTH = 200;

    /**
     * @param  array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, extra: string}  $column
     * @param  array{nonnull: int, distinct: int, maxlen: int, urls: int, emails: int, multiline: int, jsonish: int}|null  $probe
     */
    public static function infer(array $column, ?array $probe = null): string
    {
        $name = strtolower($column['name']);
        $dataType = strtolower($column['data_type']);

        if (str_contains(strtolower($column['extra']), 'auto_increment')) {
            return self::ID;
        }

        if ($name === 'airtable_record_id') {
            return self::EXTERNAL;
        }

        if ($dataType === 'date') {
            return self::DATE;
        }

        if (in_array($dataType, ['timestamp', 'datetime'], true)) {
            return self::DATETIME;
        }

        if ($dataType === 'json') {
            return self::JSON;
        }

        // tinyint(1) is the only place a boolean is distinguishable, and every one of
        // these columns is entirely NULL today, so data can never confirm it.
        if (strtolower($column['column_type']) === 'tinyint(1)') {
            return self::BOOLEAN;
        }

        if (self::isNumericDataType($dataType)) {
            if (str_ends_with($name, '_pct')) {
                return self::PERCENT;
            }

            return (int) ($column['numeric_scale'] ?? 0) > 0 ? self::DECIMAL : self::INTEGER;
        }

        return self::inferString($name, $probe);
    }

    /**
     * @param  array{nonnull: int, distinct: int, maxlen: int, urls: int, emails: int, multiline: int, jsonish: int}|null  $probe
     */
    private static function inferString(string $name, ?array $probe): string
    {
        $nonnull = (int) ($probe['nonnull'] ?? 0);

        if ($nonnull === 0) {
            return self::nameHint($name);
        }

        if ((int) ($probe['emails'] ?? 0) === $nonnull) {
            return self::EMAIL;
        }

        // A name suffix alone is not enough: google_drive_folder_path holds Windows
        // paths, and every real link column in these databases is entirely NULL.
        if ((int) ($probe['urls'] ?? 0) === $nonnull) {
            return self::URL;
        }

        if ((int) ($probe['jsonish'] ?? 0) === $nonnull) {
            return self::JSON;
        }

        if ((int) ($probe['maxlen'] ?? 0) > self::LONGTEXT_LENGTH || (int) ($probe['multiline'] ?? 0) > 0) {
            return self::LONGTEXT;
        }

        if (self::looksLikeSelect($probe)) {
            return self::SELECT;
        }

        return self::TEXT;
    }

    /**
     * With no rows to look at, the column name is the only evidence there is.
     */
    private static function nameHint(string $name): string
    {
        if (str_contains($name, 'email')) {
            return self::EMAIL;
        }

        if (str_ends_with($name, '_url') || str_ends_with($name, '_link')) {
            return self::URL;
        }

        return self::TEXT;
    }

    /**
     * @param  array{nonnull: int, distinct: int, maxlen: int, urls: int, emails: int, multiline: int, jsonish: int}|null  $probe
     */
    private static function looksLikeSelect(?array $probe): bool
    {
        $nonnull = (int) ($probe['nonnull'] ?? 0);
        $distinct = (int) ($probe['distinct'] ?? 0);

        // On an eight-row table every value is distinct, which is a primary text
        // column rather than a choice list.
        if ($distinct === 0 || $distinct >= $nonnull) {
            return false;
        }

        return $distinct <= min(self::MAX_SELECT_OPTIONS, max(3, intdiv($nonnull, 3)));
    }

    private static function isNumericDataType(string $dataType): bool
    {
        return in_array($dataType, [
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint',
            'decimal', 'numeric', 'float', 'double',
        ], true);
    }

    public static function width(string $type): int
    {
        return self::WIDTHS[$type] ?? 180;
    }

    public static function rank(string $type): int
    {
        return self::RANK[$type] ?? self::RANK[self::TEXT];
    }

    /**
     * Types a grid cell may write back. A reference is deliberately absent: repointing
     * a foreign key is a relational change, not a corrected value, and belongs to the
     * portal endpoint that owns the record.
     */
    public static function isEditable(string $type): bool
    {
        return in_array($type, [
            self::TITLE, self::TEXT, self::LONGTEXT, self::SELECT,
            self::INTEGER, self::DECIMAL, self::PERCENT, self::BOOLEAN,
            self::DATE, self::DATETIME, self::EMAIL, self::URL,
        ], true);
    }

    /**
     * Right-aligned in the grid. Identifiers held in text columns are never numeric
     * here, whatever they look like.
     */
    public static function isNumericType(string $type): bool
    {
        return in_array($type, [self::INTEGER, self::DECIMAL, self::PERCENT], true);
    }

    /**
     * Columns worth a contains search. Sorting is allowed on everything scalar.
     */
    public static function isSearchable(string $type): bool
    {
        return in_array($type, [
            self::TEXT, self::LONGTEXT, self::TITLE, self::SELECT,
            self::EMAIL, self::URL, self::EXTERNAL,
        ], true);
    }

    /**
     * Human label for a column name: id_no becomes Id No.
     */
    public static function label(string $column): string
    {
        return ucwords(str_replace('_', ' ', $column));
    }
}
