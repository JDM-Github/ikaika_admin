<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The email rules moved to match the sidebar (Email at the top level, All Emails under Administration).
 * What is pinned here is that the move carries everything stored against an old key -- presets, gifts,
 * switches -- to its new key, so nobody gains or loses a power, and that it undoes cleanly.
 */
class PortalEmailRulesMoveTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const NEW_KEYS = ['email', 'email.send', 'email.library', 'administration.emails', 'administration.emails.export'];

    private function migration(): object
    {
        return require database_path('migrations/2026_09_30_090000_move_portal_email_rules.php');
    }

    public function test_every_holder_of_an_old_email_rule_moves_to_its_new_key(): void
    {
        $portal = DB::connection('portal');
        $admin = Employee::query()->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")->orderBy('id')->firstOrFail();
        $member = Employee::query()->whereRaw("LOWER(COALESCE(role, '')) = 'user'")->orderBy('id')->firstOrFail();

        // Put the catalog back as it was, then store a gift and a switch-off on the old keys.
        $this->migration()->down();
        $this->assertFalse($portal->table('access_permissions')->whereIn('permission_key', self::NEW_KEYS)->exists());
        $presets = $portal->table('access_presets')->where('permission_key', 'like', 'administration.email%')->count();
        $portal->table('access_grants')->insert(['employee_id' => $member->getKey(), 'permission_key' => 'administration.email.outbox', 'granted_by' => $admin->getKey()]);
        $portal->table('access_toggles')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'administration.email.send']);

        $this->migration()->up();

        $this->assertFalse($portal->table('access_permissions')->where('permission_key', 'like', 'administration.email%')->where('permission_key', 'not like', 'administration.emails%')->exists());
        $this->assertSame($presets, $portal->table('access_presets')->whereIn('permission_key', self::NEW_KEYS)->count());
        $this->assertTrue($portal->table('access_grants')->where('employee_id', $member->getKey())->where('permission_key', 'administration.emails')->exists());
        $this->assertTrue($portal->table('access_toggles')->where('employee_id', $admin->getKey())->where('permission_key', 'email.send')->exists());
        $this->assertSame(
            ['administration.emails' => 'administration', 'administration.emails.export' => 'administration.emails', 'email' => null, 'email.send' => 'email', 'email.library' => 'email'],
            $portal->table('access_permissions')->whereIn('permission_key', self::NEW_KEYS)->orderBy('sort_order')->pluck('parent_key', 'permission_key')->all(),
        );
    }
}
