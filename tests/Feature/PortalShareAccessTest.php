<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalNotificationEmails;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public side of a shared page: the password, the mailed code when an email is bound, the guest
 * token, and what that token may do - GETs on the granted pages only. Revoke and expiry end a live
 * session on the next request, because the share is re-read every time.
 */
class PortalShareAccessTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/share';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $overrides
     * @return array{id: int, code: string, password: string}
     */
    private function share(array $rules = ['projects.view'], array $overrides = []): array
    {
        $code = bin2hex(random_bytes(12));
        $password = 'Guest-Pass-1';
        $id = (int) DB::connection('portal')->table('shared_pages')->insertGetId([
            'label' => 'Test share',
            'code' => $code,
            'password_hash' => Hash::make($password),
            'email' => $overrides['email'] ?? null,
            'require_otp' => $overrides['requireOtp'] ?? true,
            'expires_at' => $overrides['expiresAt'] ?? now()->addDays(7),
            'rate_per_minute' => $overrides['ratePerMinute'] ?? 60,
            'revoked_at' => $overrides['revokedAt'] ?? null,
            'created_by' => 1,
        ]);
        foreach ($rules as $key) {
            DB::connection('portal')->table('shared_page_rules')->insert(['share_id' => $id, 'rule_key' => $key]);
        }

        return ['id' => $id, 'code' => $code, 'password' => $password];
    }

    private function tokenFor(string $code, string $password): string
    {
        return (string) $this->postJson(self::BASE.'/'.$code.'/access', ['password' => $password])
            ->assertOk()
            ->json('data.token');
    }

    public function test_info_names_a_live_share_and_hides_a_dead_one(): void
    {
        $live = $this->share();

        $this->getJson(self::BASE.'/'.$live['code'])->assertOk()->assertJsonPath('data.label', 'Test share')->assertJsonPath('data.hasEmail', false);
        $this->getJson(self::BASE.'/not-a-code')->assertNotFound();

        $revoked = $this->share(overrides: ['revokedAt' => now()]);
        $this->getJson(self::BASE.'/'.$revoked['code'])->assertNotFound();

        // Expired reads exactly like unknown, and its password step is just as dead.
        $expired = $this->share(overrides: ['expiresAt' => now()->subMinute()]);
        $this->getJson(self::BASE.'/'.$expired['code'])->assertNotFound()->assertJsonPath('message', 'This link is not active.');
        $this->postJson(self::BASE.'/'.$expired['code'].'/access', ['password' => $expired['password']])->assertNotFound();
    }

    public function test_a_wrong_password_is_refused_and_the_right_one_signs_the_guest_in(): void
    {
        $share = $this->share();

        $this->postJson(self::BASE.'/'.$share['code'].'/access', ['password' => 'wrong'])->assertStatus(422);
        $token = $this->tokenFor($share['code'], $share['password']);

        $this->withToken($token)->getJson(self::BASE.'/session')
            ->assertOk()
            ->assertJsonPath('data.label', 'Test share')
            ->assertJsonPath('data.rules', ['projects.view']);
    }

    public function test_a_guest_reads_granted_pages_and_never_writes(): void
    {
        $managed = $this->share(['manage.shares']);
        $granted = $this->tokenFor($managed['code'], $managed['password']);
        $plain = $this->share(['projects.view']);
        $ungranted = $this->tokenFor($plain['code'], $plain['password']);

        // Granting manage.shares proves the rule is answered from the share, not a role.
        $this->withToken($granted)->getJson('/api/development/portal/manage/shared-pages')->assertOk();
        $this->withToken($ungranted)->getJson('/api/development/portal/manage/shared-pages')->assertForbidden();

        // Read-only: every non-GET is refused before any rule is read.
        $this->withToken($granted)->postJson('/api/development/portal/manage/shared-pages', [
            'label' => 'Nope',
            'rules' => ['manage.shares'],
        ])->assertForbidden();
    }

    public function test_an_email_binding_mails_a_code_the_guest_must_enter(): void
    {
        $share = $this->share(overrides: ['email' => 'guest@example.com']);

        $this->postJson(self::BASE.'/'.$share['code'].'/access', ['password' => $share['password']])
            ->assertOk()
            ->assertJsonPath('data.otpRequired', true);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('guest@example.com')
            && $mail->bodyFormat === PortalEmailMarkup::FORMAT_LETTER
            && $mail->fromName === PortalNotificationEmails::FROM_NAME
            && str_starts_with($mail->subjectLine, 'Your code for '));

        // The mailed code is hashed; a known one stands in so the checks can be read.
        DB::connection('portal')->table('shared_page_otps')->where('share_id', $share['id'])->update([
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson(self::BASE.'/'.$share['code'].'/verify', ['password' => $share['password'], 'otp' => '654321'])
            ->assertStatus(422);
        $this->assertSame(1, (int) DB::connection('portal')->table('shared_page_otps')->where('share_id', $share['id'])->value('attempts'));
        $this->postJson(self::BASE.'/'.$share['code'].'/verify', ['password' => $share['password'], 'otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Test share');

        // The code is spent, and a share that ran out of tries refuses even the right one.
        $this->assertNull(DB::connection('portal')->table('shared_page_otps')->where('share_id', $share['id'])->first());
        DB::connection('portal')->table('shared_page_otps')->insert([
            'share_id' => $share['id'], 'code_hash' => Hash::make('123456'), 'attempts' => 5,
            'issued_at' => now(), 'expires_at' => now()->addMinutes(10),
        ]);
        $this->postJson(self::BASE.'/'.$share['code'].'/verify', ['password' => $share['password'], 'otp' => '123456'])
            ->assertStatus(422);
    }

    public function test_an_email_bound_without_a_code_signs_in_with_the_password_alone(): void
    {
        $share = $this->share(overrides: ['email' => 'guest@example.com', 'requireOtp' => false]);

        $this->postJson(self::BASE.'/'.$share['code'].'/access', ['password' => $share['password']])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
        Mail::assertNothingSent();
        $this->postJson(self::BASE.'/'.$share['code'].'/resend', ['password' => $share['password']])
            ->assertStatus(422);
    }

    public function test_the_shares_own_rate_cap_stops_a_hammering_guest(): void
    {
        $share = $this->share(overrides: ['ratePerMinute' => 3]);
        $token = $this->tokenFor($share['code'], $share['password']);

        $this->withToken($token)->getJson(self::BASE.'/session')->assertOk();
        $this->withToken($token)->getJson(self::BASE.'/session')->assertOk();
        $this->withToken($token)->getJson(self::BASE.'/session')->assertOk();
        $this->withToken($token)->getJson(self::BASE.'/session')->assertStatus(429);
    }

    public function test_revoking_or_expiring_ends_a_live_session(): void
    {
        $share = $this->share();
        $token = $this->tokenFor($share['code'], $share['password']);
        $this->withToken($token)->getJson(self::BASE.'/session')->assertOk();

        DB::connection('portal')->table('shared_pages')->where('id', $share['id'])->update(['revoked_at' => now()]);
        $this->withToken($token)->getJson(self::BASE.'/session')->assertStatus(401);
    }

    public function test_a_sign_in_writes_a_visit_and_touches_last_used(): void
    {
        $share = $this->share();
        $this->tokenFor($share['code'], $share['password']);

        $this->assertSame(1, DB::connection('portal')->table('shared_page_visits')->where('share_id', $share['id'])->count());
        $this->assertNotNull(DB::connection('portal')->table('shared_pages')->where('id', $share['id'])->value('last_used_at'));
    }
}
