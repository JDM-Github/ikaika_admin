<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Portal\PortalAdvancedSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Settings' Advanced card: the notification email switch moves from the .env flag to a stored
 * choice, read and written only while Developer mode is on, and the .env value stays the default
 * until a choice is stored.
 */
class PortalAdvancedSettingsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const URL = '/api/development/portal/settings/advanced';

    private Employee $developer;

    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->whereIn('setting_key', [PortalAdvancedSettings::NOTIFICATION_EMAILS, PortalAdvancedSettings::ID_LOGIN])
            ->delete();

        $this->developer = $this->active();
        $this->member = $this->active(1);
        // A member-role developer, so Default visibly means "a member".
        $this->developer->forceFill(['is_developer' => true, 'department' => 'MIS'])->save();
        $this->member->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();
    }

    public function test_the_read_answers_the_config_default_until_a_choice_is_stored(): void
    {
        config(['products.catalog.portal.notification_emails' => true]);
        $this->read($this->developerToken())->assertOk()->assertJsonPath('data.notificationEmails', true);

        config(['products.catalog.portal.notification_emails' => false]);
        $this->read($this->developerToken())->assertOk()->assertJsonPath('data.notificationEmails', false);
    }

    // The card only exists in Developer mode, so both routes refuse Default and plain members.
    public function test_only_developer_mode_reads_and_writes(): void
    {
        $default = $this->login($this->developer);
        $this->read($default)->assertForbidden();
        $this->write($default, true)->assertForbidden();

        $member = $this->login($this->member);
        $this->read($member)->assertForbidden();
        $this->write($member, true)->assertForbidden();
    }

    public function test_a_write_persists_the_choice_and_the_read_answers_it(): void
    {
        $token = $this->developerToken();

        $this->write($token, false)->assertOk()->assertJsonPath('data.notificationEmails', false);
        $this->assertSame('0', DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::NOTIFICATION_EMAILS)
            ->value('setting_value'));
        $this->read($token)->assertOk()->assertJsonPath('data.notificationEmails', false);

        $this->write($token, true)->assertOk()->assertJsonPath('data.notificationEmails', true);
        $this->read($token)->assertOk()->assertJsonPath('data.notificationEmails', true);

        // The write is audible: a developer's act, on its own resource.
        $log = PortalLog::query()
            ->where('employee_id', $this->developer->getKey())
            ->where('resource', 'settings.advanced')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertSame('PATCH', $log->action);
        $this->assertTrue((bool) $log->as_developer);
        $this->assertTrue((bool) ($log->payload['notificationEmails'] ?? false));
    }

    public function test_a_write_needs_the_boolean_switch(): void
    {
        $this->withToken($this->developerToken())
            ->patchJson(self::URL, [])
            ->assertStatus(422);
    }

    // The reported ask (2026-10-14, user): ID sign-in follows a stored switch, so a deployed
    // portal offers the box only when a developer turns it on.
    public function test_the_id_login_switch_is_off_until_a_developer_turns_it_on(): void
    {
        $public = '/api/development/portal/auth/id-login';
        $this->getJson($public)->assertOk()->assertJsonPath('data.idLogin', false);

        $token = $this->developerToken();
        $this->withToken($token)->patchJson(self::URL, ['idLogin' => true])->assertOk()->assertJsonPath('data.idLogin', true);
        $this->assertSame('1', DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::ID_LOGIN)
            ->value('setting_value'));

        // The public read follows, so the login screen can offer the box with no session.
        $this->getJson($public)->assertOk()->assertJsonPath('data.idLogin', true);
        $this->read($token)->assertOk()->assertJsonPath('data.idLogin', true);

        // The write is audible: a developer's act, on its own resource.
        $log = PortalLog::query()
            ->where('employee_id', $this->developer->getKey())
            ->where('resource', 'settings.advanced')
            ->orderByDesc('id')
            ->firstOrFail();
        $this->assertTrue((bool) ($log->payload['idLogin'] ?? false));

        $this->withToken($token)->patchJson(self::URL, ['idLogin' => false])->assertOk()->assertJsonPath('data.idLogin', false);
        $this->getJson($public)->assertOk()->assertJsonPath('data.idLogin', false);
    }

    // The reported bug (2026-10-14, user): the box showed on the Test Server but the sign-in itself answered 404.
    public function test_a_deployed_portal_signs_in_by_id_only_while_the_switch_is_on(): void
    {
        $this->app['env'] = 'production';
        $url = '/api/development/portal/auth/login';

        $this->postJson($url, ['id_no' => $this->member->id_no])->assertNotFound();

        DB::connection('core')->table('settings')->insert([
            'product' => 'portal',
            'setting_key' => PortalAdvancedSettings::ID_LOGIN,
            'setting_value' => '1',
        ]);

        // The switch alone is not enough (2026-10-14, user): the MIS-held password must match, and none configured refuses all.
        config(['services.portal_id_login.password' => '']);
        $this->postJson($url, ['id_no' => $this->member->id_no, 'password' => ''])->assertUnauthorized();

        config(['services.portal_id_login.password' => 'mis-only']);
        $this->postJson($url, ['id_no' => $this->member->id_no])->assertUnauthorized();
        $this->postJson($url, ['id_no' => $this->member->id_no, 'password' => 'wrong'])->assertUnauthorized();
        $this->postJson($url, ['id_no' => $this->member->id_no, 'password' => 'mis-only'])->assertOk()->assertJsonStructure(['token']);
    }

    private function developerToken(): string
    {
        return $this->developerModeOn($this->login($this->developer));
    }

    private function login(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])
            ->assertOk()
            ->json('token');
    }

    private function read(string $token): TestResponse
    {
        return $this->withToken($token)->getJson(self::URL);
    }

    private function write(string $token, bool $on): TestResponse
    {
        return $this->withToken($token)->patchJson(self::URL, ['notificationEmails' => $on]);
    }

    // The nth active, non-executive employee; the caller decides who is the developer.
    private function active(int $skip = 0): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->orderBy('id')
            ->skip($skip)
            ->firstOrFail();
    }
}
