<?php

namespace Tests;

use App\Support\Portal\PortalJwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
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
