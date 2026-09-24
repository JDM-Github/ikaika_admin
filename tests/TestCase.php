<?php

namespace Tests;

use App\Support\Portal\PortalJwt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
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
