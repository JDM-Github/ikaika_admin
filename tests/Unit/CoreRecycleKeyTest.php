<?php

namespace Tests\Unit;

use App\Support\Core\CoreRecycleKey;
use PHPUnit\Framework\TestCase;

class CoreRecycleKeyTest extends TestCase
{
    public function test_submitted_report_key_is_employee_and_group_id(): void
    {
        $this->assertSame(
            '12:2026-08-24-daily',
            CoreRecycleKey::submittedReport(12, '2026-08-24', 'daily'),
        );
    }
}
