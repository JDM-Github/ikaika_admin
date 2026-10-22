<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalDeveloperMode;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Sync to Airtable from All Actions: confirmed with the person's own Developer mode password, it
 * previews what the next send would do (writing nothing) and, after another confirmation, sends.
 * The password is never the one-time default, and only developers have one.
 */
class PortalAirtableSyncPreviewTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const URL = '/api/development/portal/administration/actions/sync-preview';

    private const SEND_URL = '/api/development/portal/administration/actions/sync-send';

    private Employee $developer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.airtable.token' => 'test-token', 'services.airtable.base' => 'appTESTBASE000000']);
        foreach (['access_grants', 'access_limits', 'access_personal', 'access_toggles', 'developer_modes'] as $table) {
            DB::connection('portal')->table($table)->delete();
        }
        $this->developer = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('is_developer', true)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    // Sync to Airtable is a developer's power, held in Developer mode.
    private function developerToken(): string
    {
        return $this->developerModeOn($this->token($this->developer));
    }

    private function setPassword(string $password): void
    {
        DB::connection('portal')->table('developer_modes')->insert([
            'employee_id' => $this->developer->getKey(),
            'password_hash' => Hash::make($password),
        ]);
    }

    public function test_the_right_password_previews_and_writes_nothing_anywhere(): void
    {
        Http::fake(['api.airtable.com/v0/meta/*' => Http::response(['tables' => []])]);
        $this->setPassword('correct-horse-battery');
        $unsynced = Action::query()->whereNull('synced_at')->count();
        $token = $this->developerToken();

        $response = $this->withToken($token)->postJson(self::URL, ['password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('resource', 'airtable-sync-preview');

        $this->assertIsArray($response->json('data.counts'));
        $this->assertCount($unsynced, $response->json('data.items'));
        $this->assertArrayNotHasKey('fields', $response->json('data.items.0') ?? []);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() !== 'GET');
        $this->assertSame($unsynced, Action::query()->whereNull('synced_at')->count());
        $this->assertStringStartsWith('{UserName|You} previewed the Airtable sync', (string) DB::connection('portal')->table('logs')
            ->where('employee_id', $this->developer->getKey())->where('resource', 'administration.actions.sync')->orderByDesc('id')->value('message'));
    }

    public function test_the_send_runs_when_nothing_is_waiting(): void
    {
        Http::fake();
        $this->setPassword('correct-horse-battery');
        Action::query()->whereNull('synced_at')->update(['synced_at' => now()]);

        $this->withToken($this->developerToken())->postJson(self::SEND_URL, ['password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('resource', 'airtable-sync-sent')
            ->assertJsonPath('data.stopped', false);
    }

    public function test_the_send_is_refused_for_the_prod_profile_outside_production(): void
    {
        $this->setPassword('correct-horse-battery');
        config(['services.airtable.env' => 'prod']);

        $this->withToken($this->developerToken())->postJson(self::SEND_URL, ['password' => 'correct-horse-battery'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Refused: AIRTABLE_ENV=prod from a non-production app.');
    }

    public function test_the_send_refuses_while_another_send_holds_the_lock(): void
    {
        $this->setPassword('correct-horse-battery');
        $lock = Cache::lock('portal:airtable-sync', 60);
        $this->assertTrue($lock->get());
        try {
            $this->withToken($this->developerToken())->postJson(self::SEND_URL, ['password' => 'correct-horse-battery'])
                ->assertStatus(409)
                ->assertJsonPath('message', 'Another Airtable send is already running; nothing was sent.');
        } finally {
            $lock->release();
        }
    }

    public function test_a_wrong_password_previews_nothing(): void
    {
        Http::fake();
        $this->setPassword('correct-horse-battery');

        $this->withToken($this->developerToken())->postJson(self::URL, ['password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That password is not right.');
        Http::assertNothingSent();
    }

    // The one-time default only ever switches Developer mode on; it never confirms an action.
    public function test_without_a_password_of_their_own_they_are_asked_to_set_one(): void
    {
        Http::fake();

        $this->withToken($this->developerToken())->postJson(self::URL, ['password' => PortalDeveloperMode::DEFAULT_PASSWORD])
            ->assertStatus(428)
            ->assertJsonPath('message', 'Set your Developer mode password in Settings before confirming.');
        $this->withToken($this->developerToken())->postJson(self::URL, [])->assertStatus(422);
    }

    public function test_only_developers_can_confirm(): void
    {
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('is_developer', false)
            ->orderBy('id')
            ->firstOrFail();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Confirming needs a Developer mode password, which only developers have.');
        app(PortalDeveloperMode::class)->confirm($admin, 'anything');
    }

    public function test_without_the_sync_power_the_preview_is_refused(): void
    {
        $member = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->where('is_developer', false)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();

        $this->withToken($this->token($member))->postJson(self::URL, ['password' => 'x'])->assertForbidden();
    }

    // Airtable unreachable: a plain reason, never the token.
    public function test_an_unreachable_airtable_reads_as_a_plain_reason(): void
    {
        Http::fake(['api.airtable.com/*' => Http::response(['error' => 'down'], 500)]);
        $this->setPassword('correct-horse-battery');

        $response = $this->withToken($this->developerToken())->postJson(self::URL, ['password' => 'correct-horse-battery'])
            ->assertStatus(502);

        $this->assertStringStartsWith('Airtable could not be read', (string) $response->json('message'));
        $this->assertStringNotContainsString('test-token', (string) $response->getContent());
    }
}
