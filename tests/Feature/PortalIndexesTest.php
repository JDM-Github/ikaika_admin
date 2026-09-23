<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

class PortalIndexesTest extends TestCase
{
    public function test_user_reports_is_indexed_by_report_date(): void
    {
        $this->assertTrue(
            Schema::connection('portal')->hasTable('user_reports'),
            'portal.user_reports must exist',
        );
        $this->assertContains('idx_user_reports_date', $this->indexNames('portal', 'user_reports'));
    }

    public function test_estimator_holidays_are_indexed_by_date_when_that_table_exists(): void
    {
        try {
            if (! Schema::connection('project_estimator')->hasTable('holidays')) {
                $this->markTestSkipped('estimator holidays table is not present');
            }
        } catch (Throwable) {
            $this->markTestSkipped('estimator database is not reachable');
        }

        $this->assertContains('idx_pe_holidays_date', $this->indexNames('project_estimator', 'holidays'));
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $connection, string $table): array
    {
        $names = [];
        foreach (DB::connection($connection)->select('show index from '.$table) as $row) {
            $names[] = (string) $row->Key_name;
        }

        return $names;
    }
}
