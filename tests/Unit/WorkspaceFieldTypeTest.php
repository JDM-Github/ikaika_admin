<?php

namespace Tests\Unit;

use App\Support\Workspace\WorkspaceFieldType;
use PHPUnit\Framework\TestCase;

class WorkspaceFieldTypeTest extends TestCase
{
    public function test_the_declared_shape_decides_before_any_data_is_looked_at(): void
    {
        $this->assertSame(WorkspaceFieldType::ID, WorkspaceFieldType::infer($this->column('id', 'int', 'int unsigned', extra: 'auto_increment')));
        $this->assertSame(WorkspaceFieldType::EXTERNAL, WorkspaceFieldType::infer($this->column('airtable_record_id', 'varchar', 'varchar(20)')));
        $this->assertSame(WorkspaceFieldType::DATE, WorkspaceFieldType::infer($this->column('report_date', 'date', 'date')));
        $this->assertSame(WorkspaceFieldType::DATETIME, WorkspaceFieldType::infer($this->column('date_created', 'timestamp', 'timestamp')));
        $this->assertSame(WorkspaceFieldType::JSON, WorkspaceFieldType::infer($this->column('parameters', 'json', 'json')));
    }

    public function test_a_boolean_is_only_visible_in_the_column_type(): void
    {
        // Every tinyint(1) in these databases is entirely NULL, so data can never confirm it.
        $this->assertSame(WorkspaceFieldType::BOOLEAN, WorkspaceFieldType::infer($this->column('is_ledger_details_updated', 'tinyint', 'tinyint(1)')));
        $this->assertSame(WorkspaceFieldType::INTEGER, WorkspaceFieldType::infer($this->column('sequence', 'tinyint', 'tinyint(4)')));
    }

    public function test_numeric_scale_separates_a_measure_from_a_count_and_a_percent(): void
    {
        $this->assertSame(WorkspaceFieldType::DECIMAL, WorkspaceFieldType::infer($this->column('area_sqft', 'decimal', 'decimal(12,2)', scale: 2)));
        $this->assertSame(WorkspaceFieldType::INTEGER, WorkspaceFieldType::infer($this->column('no_of_floors', 'decimal', 'decimal(6,0)', scale: 0)));
        $this->assertSame(WorkspaceFieldType::PERCENT, WorkspaceFieldType::infer($this->column('progress_pct', 'decimal', 'decimal(5,2)', scale: 2)));
    }

    public function test_a_name_suffix_alone_never_makes_a_url(): void
    {
        // google_drive_folder_path holds Windows paths in 51 of 120 portal projects.
        $windowsPaths = $this->probe(nonnull: 51, distinct: 51, maxlen: 40, urls: 0);
        $this->assertSame(WorkspaceFieldType::TEXT, WorkspaceFieldType::infer($this->column('google_drive_folder_path', 'varchar', 'varchar(1000)'), $windowsPaths));

        $realUrls = $this->probe(nonnull: 9, distinct: 9, maxlen: 395, urls: 9);
        $this->assertSame(WorkspaceFieldType::URL, WorkspaceFieldType::infer($this->column('profile_folder_url', 'varchar', 'varchar(500)'), $realUrls));

        // A column with no rows at all falls back to its name.
        $empty = $this->probe(nonnull: 0, distinct: 0, maxlen: 0);
        $this->assertSame(WorkspaceFieldType::URL, WorkspaceFieldType::infer($this->column('ms_planner_link', 'varchar', 'varchar(1000)'), $empty));
        $this->assertSame(WorkspaceFieldType::EMAIL, WorkspaceFieldType::infer($this->column('assignee_email', 'varchar', 'varchar(255)'), $empty));
    }

    public function test_an_email_column_needs_every_populated_value_to_be_an_address(): void
    {
        $allEmails = $this->probe(nonnull: 19, distinct: 19, maxlen: 30, emails: 19);
        $this->assertSame(WorkspaceFieldType::EMAIL, WorkspaceFieldType::infer($this->column('email', 'varchar', 'varchar(255)'), $allEmails));

        $someEmails = $this->probe(nonnull: 19, distinct: 19, maxlen: 30, emails: 12);
        $this->assertNotSame(WorkspaceFieldType::EMAIL, WorkspaceFieldType::infer($this->column('email', 'varchar', 'varchar(255)'), $someEmails));
    }

    public function test_a_small_table_of_unique_names_is_a_title_column_not_a_choice_list(): void
    {
        // clients has seven rows and seven distinct names -- distinct == nonnull.
        $sevenUnique = $this->probe(nonnull: 7, distinct: 7, maxlen: 20);
        $this->assertSame(WorkspaceFieldType::TEXT, WorkspaceFieldType::infer($this->column('name', 'varchar', 'varchar(255)'), $sevenUnique));

        // projects.status is three values over 118 rows.
        $threeOf118 = $this->probe(nonnull: 118, distinct: 3, maxlen: 7);
        $this->assertSame(WorkspaceFieldType::SELECT, WorkspaceFieldType::infer($this->column('status', 'varchar', 'varchar(100)'), $threeOf118));
    }

    public function test_text_is_only_long_text_when_the_data_is_long(): void
    {
        // Most TEXT columns in these databases are short: project_name tops out at 46.
        $short = $this->probe(nonnull: 120, distinct: 120, maxlen: 46);
        $this->assertSame(WorkspaceFieldType::TEXT, WorkspaceFieldType::infer($this->column('project_name', 'text', 'text'), $short));

        $long = $this->probe(nonnull: 1812, distinct: 1700, maxlen: 1341, multiline: 40);
        $this->assertSame(WorkspaceFieldType::LONGTEXT, WorkspaceFieldType::infer($this->column('remarks', 'text', 'text'), $long));
    }

    public function test_identity_and_title_lead_the_column_order_and_the_audit_trail_follows(): void
    {
        $this->assertLessThan(WorkspaceFieldType::rank(WorkspaceFieldType::TITLE), WorkspaceFieldType::rank(WorkspaceFieldType::ID));
        $this->assertLessThan(WorkspaceFieldType::rank(WorkspaceFieldType::TEXT), WorkspaceFieldType::rank(WorkspaceFieldType::SELECT));

        // The estimator's projects table carries nine near-identical LOD selects; if
        // those outranked links, the Key preset would never reach a linked record.
        $this->assertLessThan(WorkspaceFieldType::rank(WorkspaceFieldType::SELECT), WorkspaceFieldType::rank(WorkspaceFieldType::LINK));
        $this->assertLessThan(WorkspaceFieldType::rank(WorkspaceFieldType::EXTERNAL), WorkspaceFieldType::rank(WorkspaceFieldType::LONGTEXT));
    }

    public function test_only_real_numbers_are_right_aligned(): void
    {
        $this->assertTrue(WorkspaceFieldType::isNumericType(WorkspaceFieldType::DECIMAL));
        $this->assertTrue(WorkspaceFieldType::isNumericType(WorkspaceFieldType::PERCENT));

        // clients.client_id holds 00 and 01, and activity_codes.id_no holds 8601.
        $this->assertFalse(WorkspaceFieldType::isNumericType(WorkspaceFieldType::TEXT));
        $this->assertFalse(WorkspaceFieldType::isNumericType(WorkspaceFieldType::EXTERNAL));
    }

    public function test_a_column_name_becomes_a_readable_label(): void
    {
        $this->assertSame('Id No', WorkspaceFieldType::label('id_no'));
        $this->assertSame('Project Name', WorkspaceFieldType::label('project_name'));
    }

    /**
     * @return array{name: string, data_type: string, column_type: string, max_length: ?int, numeric_scale: ?int, extra: string}
     */
    private function column(string $name, string $dataType, string $columnType, ?int $scale = null, string $extra = ''): array
    {
        return [
            'name' => $name,
            'data_type' => $dataType,
            'column_type' => $columnType,
            'max_length' => null,
            'numeric_scale' => $scale,
            'extra' => $extra,
        ];
    }

    /**
     * @return array{nonnull: int, distinct: int, maxlen: int, urls: int, emails: int, multiline: int, jsonish: int}
     */
    private function probe(int $nonnull, int $distinct, int $maxlen, int $urls = 0, int $emails = 0, int $multiline = 0, int $jsonish = 0): array
    {
        return [
            'nonnull' => $nonnull,
            'distinct' => $distinct,
            'maxlen' => $maxlen,
            'urls' => $urls,
            'emails' => $emails,
            'multiline' => $multiline,
            'jsonish' => $jsonish,
        ];
    }
}
