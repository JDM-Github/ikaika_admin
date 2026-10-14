<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalNotificationEmails;
use App\Support\Portal\PortalQrPng;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / Shared Pages: guest read-only links, an administrator-tier fine-grained power since
 * 2026-10-09: `manage.shares` opens and reads (the link, its QR and the kept password),
 * `manage.shares.manage` creates, changes and revokes, and `manage.shares.password` regenerates.
 */
class PortalSharedPagesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/shared-pages';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    // The working calls sign in a developer in Developer mode, which holds every key of the page.
    private function developerToken(): string
    {
        $developer = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('is_developer', true)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();

        return $this->developerModeOn($this->token($developer));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Truebeck walkthrough',
            'expiresInDays' => 7,
            'ratePerMinute' => 60,
            'rules' => ['projects.view', 'finance.envelopes'],
        ], $overrides);
    }

    private function createShare(string $token, array $overrides = []): TestResponse
    {
        return $this->withToken($token)->postJson(self::BASE, $this->payload($overrides))->assertOk();
    }

    // The page is an administrator's now: an admin opens it in Default; a member never can.
    public function test_the_page_follows_its_rule(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
        $this->withToken($this->token($admin))->getJson(self::BASE)->assertOk();
    }

    // The write keys split from the read: an admin limited out of either keeps exactly what is left.
    public function test_the_manage_and_password_keys_gate_their_own_actions(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);
        DB::connection('portal')->table('access_presets')->where('role', 'admin')
            ->whereIn('permission_key', ['manage.shares.manage', 'manage.shares.password'])->delete();

        $this->withToken($token)->getJson(self::BASE)->assertOk();
        $this->withToken($token)->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->withToken($token)->postJson(self::BASE.'/1/regenerate')->assertForbidden();

        // With Create and revoke back, creating works in Default; New passwords still needs its own key.
        DB::connection('portal')->table('access_presets')->insert([
            'role' => 'admin', 'permission_key' => 'manage.shares.manage', 'reach' => null, 'include_executives' => 0,
        ]);
        $share = $this->createShare($token)->json('data.share');
        $this->withToken($token)->postJson(self::BASE.'/'.$share['id'].'/regenerate')->assertForbidden();
        $this->withToken($token)->deleteJson(self::BASE.'/'.$share['id'])->assertOk();
    }

    public function test_a_share_is_created_with_its_password_kept_in_the_record(): void
    {
        $token = $this->developerToken();

        $created = $this->createShare($token)->assertJsonPath('data.share.label', 'Truebeck walkthrough');
        $share = $created->json('data.share');
        $password = $created->json('data.password');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $share['code']);
        $this->assertStringEndsWith('/share/'.$share['code'], $share['link']);
        $this->assertIsString($password);
        $this->assertSame(12, strlen($password));
        // The record carries the readable password too, so it can be sent again.
        $this->assertSame($password, $share['password']);
        $this->assertSame(['projects.view', 'finance.envelopes'], $share['rules']);
        $this->assertNull($share['email']);
        $this->assertFalse($share['isRevoked']);
        $this->assertNotNull($share['expiresAt']);

        $row = DB::connection('portal')->table('shared_pages')->where('id', $share['id'])->first();
        $this->assertNotNull($row);
        $this->assertNotSame($password, $row->password_hash);
        $this->assertTrue(Hash::check($password, (string) $row->password_hash));
        $this->assertSame($password, $row->password_value);
        $this->assertNull($row->revoked_at);
        $this->assertSame(['finance.envelopes', 'projects.view'], DB::connection('portal')->table('shared_page_rules')
            ->where('share_id', $share['id'])->orderBy('rule_key')->pluck('rule_key')->all());

        // The list carries it back, and the page list carries every shareable page.
        $listed = $this->withToken($token)->getJson(self::BASE)->assertOk();
        $listedShare = collect($listed->json('data.shares'))->firstWhere('id', $share['id']);
        $this->assertSame($share['code'], $listedShare['code']);
        $this->assertSame($password, $listedShare['password']);
        $this->assertContains('projects.view', array_column($listed->json('data.pages'), 'key'));
        $this->assertTrue(collect($listed->json('data.pages'))->firstWhere('key', 'projects.view')['isReady']);
    }

    public function test_a_typed_slug_becomes_the_link_and_a_guest_name_is_kept(): void
    {
        $token = $this->developerToken();

        $created = $this->createShare($token, ['slug' => 'truebeck', 'guestName' => 'Truebeck']);
        $share = $created->json('data.share');

        $this->assertSame('truebeck', $share['code']);
        $this->assertStringEndsWith('/share/truebeck', $share['link']);
        $this->assertSame('Truebeck', $share['guestName']);

        // The guest-facing name is the guest name; the developer's label stays the record's own.
        $this->getJson('/api/development/portal/share/truebeck')->assertOk()->assertJsonPath('data.label', 'Truebeck');

        // The sign-in answer and the restored session both call the guest by their name.
        $guestToken = (string) $this->postJson('/api/development/portal/share/truebeck/access', ['password' => $created->json('data.password')])
            ->assertOk()
            ->assertJsonPath('data.label', 'Truebeck')
            ->json('data.token');
        $this->withToken($guestToken)->getJson('/api/development/portal/share/session')
            ->assertOk()
            ->assertJsonPath('data.label', 'Truebeck');
    }

    public function test_a_blank_slug_still_generates_a_code_and_clears_the_guest_name(): void
    {
        $token = $this->developerToken();
        $share = $this->createShare($token, ['slug' => '   ', 'guestName' => ''])->json('data.share');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $share['code']);
        $this->assertNull($share['guestName']);
        // With no guest name, the label is what the guest sees.
        $this->getJson('/api/development/portal/share/'.$share['code'])->assertOk()->assertJsonPath('data.label', 'Truebeck walkthrough');
    }

    public function test_a_taken_or_malformed_slug_is_refused(): void
    {
        $token = $this->developerToken();
        $this->createShare($token, ['slug' => 'truebeck']);

        foreach (['truebeck', 'Truebeck', 'no', 'has_underscore', 'trailing-', 'session'] as $slug) {
            $this->withToken($token)->postJson(self::BASE, $this->payload(['slug' => $slug]))->assertStatus(422);
        }
    }

    public function test_an_edit_can_change_the_slug_and_the_old_link_dies(): void
    {
        $token = $this->developerToken();
        $share = $this->createShare($token)->json('data.share');

        $saved = $this->withToken($token)->patchJson(self::BASE.'/'.$share['id'], $this->payload([
            'slug' => 'truebeck-2', 'guestName' => 'Truebeck',
        ]))->assertOk()->json('data.share');

        $this->assertSame('truebeck-2', $saved['code']);
        $this->assertSame('Truebeck', $saved['guestName']);
        $this->getJson('/api/development/portal/share/'.$share['code'])->assertStatus(404);
        $this->getJson('/api/development/portal/share/truebeck-2')->assertOk();

        // A blank slug on the next edit keeps the link the share already has.
        $kept = $this->withToken($token)->patchJson(self::BASE.'/'.$share['id'], $this->payload(['slug' => '']))->assertOk()->json('data.share');
        $this->assertSame('truebeck-2', $kept['code']);
    }

    public function test_a_password_is_mailed_when_an_email_is_bound(): void
    {
        $token = $this->developerToken();

        $created = $this->withToken($token)
            ->postJson(self::BASE, $this->payload(['email' => 'guest@example.com']))
            ->assertOk();
        $password = (string) $created->json('data.password');

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('guest@example.com')
            && str_contains($mail->body, $password)
            && str_contains($mail->body, '/share/'.$created->json('data.share.code')));

        // The same letter the composer sends: letter design, the Company footer, from IKAIKA Portal.
        [$footerBody] = PortalNotificationEmails::companyFooter();
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('guest@example.com')
            && $mail->bodyFormat === PortalEmailMarkup::FORMAT_LETTER
            && $mail->fromName === PortalNotificationEmails::FROM_NAME
            && $mail->footerBody === $footerBody
            && str_starts_with($mail->body, 'Hi ')
            && str_starts_with($mail->subjectLine, 'Your link and password for '));

        // The QR rides inline as a PNG, shown in the letter where the body names it.
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => str_contains($mail->body, '](cid:share-qr)')
            && count($mail->inlineImages) === 1
            && $mail->inlineImages[0]['name'] === 'share-qr'
            && $mail->inlineImages[0]['mime'] === 'image/png'
            && $mail->inlineImages[0]['data'] === base64_encode(PortalQrPng::png((string) $created->json('data.share.link'))));

        // A new password says so in its own subject.
        $this->withToken($token)->postJson(self::BASE.'/'.$created->json('data.share.id').'/regenerate')->assertOk();
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => str_starts_with($mail->subjectLine, 'Your new password for ')
            && $mail->bodyFormat === PortalEmailMarkup::FORMAT_LETTER);
    }

    public function test_an_email_can_be_bound_without_asking_for_a_code(): void
    {
        $token = $this->developerToken();

        $created = $this->withToken($token)
            ->postJson(self::BASE, $this->payload(['email' => 'guest@example.com', 'requireOtp' => false]))
            ->assertOk()
            ->assertJsonPath('data.share.requireOtp', false);
        $password = (string) $created->json('data.password');

        // The email still carries the password; only the sign-in code is skipped.
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('guest@example.com')
            && str_contains($mail->body, $password));

        // Left out, the switch keeps asking, as every share did before it existed.
        $this->createShare($token, ['email' => 'other@example.com'])->assertJsonPath('data.share.requireOtp', true);
    }

    public function test_a_page_that_is_not_ready_is_refused(): void
    {
        // Every seeded page is ready now, so the gate is checked against one switched off on purpose.
        $rule = 'calendar';
        DB::connection('portal')->table('shared_page_shareable')->where('rule_key', $rule)->update(['is_ready' => 0]);

        $this->withToken($this->developerToken())
            ->postJson(self::BASE, $this->payload(['rules' => [$rule]]))
            ->assertStatus(422);
    }

    public function test_a_share_is_edited_and_revoked(): void
    {
        $token = $this->developerToken();
        $share = $this->createShare($token)->json('data.share');

        $this->withToken($token)
            ->patchJson(self::BASE.'/'.$share['id'], $this->payload([
                'label' => 'Truebeck, second look',
                'ratePerMinute' => 20,
                'rules' => ['projects.track'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.share.label', 'Truebeck, second look');
        $this->assertSame(['projects.track'], collect($this->withToken($token)->getJson(self::BASE)->json('data.shares'))->firstWhere('id', $share['id'])['rules']);

        $this->withToken($token)->deleteJson(self::BASE.'/'.$share['id'])
            ->assertOk()
            ->assertJsonPath('data.share.isRevoked', true);
        $this->withToken($token)->patchJson(self::BASE.'/99999999', $this->payload())->assertNotFound();
        $this->withToken($token)->deleteJson(self::BASE.'/99999999')->assertNotFound();
    }

    public function test_regenerating_replaces_the_password(): void
    {
        $token = $this->developerToken();
        $created = $this->createShare($token);
        $share = $created->json('data.share');
        $oldPassword = (string) $created->json('data.password');

        $regenerated = $this->withToken($token)->postJson(self::BASE.'/'.$share['id'].'/regenerate')->assertOk()->json('data.password');
        $this->assertIsString($regenerated);
        $this->assertNotSame($oldPassword, $regenerated);
        // The record keeps the new one, so the old one is gone from the list as well as the hash.
        $this->assertSame($regenerated, DB::connection('portal')->table('shared_pages')->where('id', $share['id'])->value('password_value'));
        $this->assertSame($regenerated, collect($this->withToken($token)->getJson(self::BASE)->json('data.shares'))->firstWhere('id', $share['id'])['password']);

        // The new one signs in; the password it replaced does not.
        $this->postJson('/api/development/portal/share/'.$share['code'].'/access', ['password' => $regenerated])->assertOk();
        $this->postJson('/api/development/portal/share/'.$share['code'].'/access', ['password' => $oldPassword])->assertStatus(422);
    }

    public function test_the_qr_is_an_svg_of_the_link(): void
    {
        $token = $this->developerToken();
        $share = $this->createShare($token)->json('data.share');

        $svg = $this->withToken($token)->getJson(self::BASE.'/'.$share['id'].'/qr')->assertOk()->json('data.svg');
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    // The reported ask (2026-10-14, user): a granted page carries a reach, never wider than the
    // actor holds, and a page the actor does not hold cannot be handed out at all.
    public function test_a_granted_page_carries_a_reach_never_wider_than_the_actor_holds(): void
    {
        $developer = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('is_developer', true)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')->firstOrFail();
        $token = $this->developerModeOn($this->token($developer));

        // Narrowed to their department themselves: an asked-for Every department lands as department.
        DB::connection('portal')->table('access_self_reach')->updateOrInsert(
            ['employee_id' => $developer->getKey(), 'permission_key' => 'projects.view'],
            ['reach' => 'department'],
        );
        $created = $this->createShare($token, ['rules' => ['projects.view'], 'reaches' => ['projects.view' => 'all']]);
        $this->assertSame(['projects.view' => 'department'], (array) $created->json('data.share.reaches'));
        $id = (int) $created->json('data.share.id');
        $this->assertSame('department', DB::connection('portal')->table('shared_page_rules')->where('share_id', $id)->value('reach'));
        // The department scope is snapshotted from the actor, for the guest's stand-in.
        $this->assertSame(trim((string) $developer->department), trim((string) DB::connection('portal')->table('shared_pages')->where('id', $id)->value('reach_department')));

        // The list tells the form the reach the actor holds for each page.
        $pages = collect($this->withToken($token)->getJson(self::BASE)->json('data.pages'))->keyBy('key');
        $this->assertSame('department', $pages['projects.view']['reach']);
        $this->assertTrue($pages['projects.view']['supportsReach']);
        $this->assertNull($pages['finance.envelopes']['reach']);

        // A guest has no records of their own: only the person is refused, whatever was asked.
        $this->withToken($token)->postJson(self::BASE, $this->payload(['rules' => ['projects.view'], 'reaches' => ['projects.view' => 'own']]))->assertStatus(422);

        // Switched off for themselves: the page cannot be handed out at all.
        DB::connection('portal')->table('access_toggles')->insert(['employee_id' => $developer->getKey(), 'permission_key' => 'projects.view']);
        $this->withToken($token)->postJson(self::BASE, $this->payload(['rules' => ['projects.view']]))->assertStatus(422);
    }
}
