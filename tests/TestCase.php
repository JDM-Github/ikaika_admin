<?php

namespace Tests;

use App\Support\Portal\PortalJwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ChecksAirtableLive;

abstract class TestCase extends BaseTestCase
{
    use ChecksAirtableLive;

    // Every portal write must carry a location (RequirePortalLocation); a test of that refusal flushes it.
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Portal-Location', 'Calamba City, Laguna, Philippines');
        $this->markAirtableLedger();
    }

    // With AIRTABLE_LIVE_SCENARIOS=1 the test's ledger is dry-run against the live base first; the
    // rollback always follows, even when that check fails.
    protected function tearDown(): void
    {
        try {
            $this->checkAirtableLive();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Drops the rule check's own reads (catalog, presets, gifts, limits, switches, reaches,
     * Developer mode and the roster it judges), so a query budget measures only the endpoint.
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    protected function withoutRuleCheck(array $queries): array
    {
        return array_values(array_filter($queries, static function (string $sql): bool {
            if (preg_match('/from `(access_[a-z_]+|developer_modes)`/', $sql) === 1) {
                return false;
            }

            return ! (str_contains($sql, 'from `employees`') && ! str_contains($sql, 'where'));
        }));
    }

    // All Backups is developer-only while parked; gift-chain tests borrow its rules as powers only developers start with.
    protected function useBackupAsAPlainPower(): void
    {
        DB::connection('portal')->table('access_permissions')->where('permission_key', 'like', 'administration.backup%')->update(['min_tier' => null]);
    }

    // Switches Developer mode on for this signed-in token, as the Settings switch would; it lasts for that sign-in only.
    protected function developerModeOn(string $token): string
    {
        $payload = app(PortalJwt::class)->parse($token);
        DB::connection('portal')->table('developer_modes')->updateOrInsert(
            ['employee_id' => (int) $payload['sub']],
            ['active_session' => (string) $payload['sid']],
        );

        return $token;
    }
}
