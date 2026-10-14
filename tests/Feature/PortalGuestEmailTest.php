<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The one write a guest may make (2026-10-14, user): sending email from the composer a share grants.
 * The send lands in All Emails like anyone's, recorded with no sender -- a guest is no employee.
 */
class PortalGuestEmailTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const SHARE = '/api/development/portal/share';

    private const SEND = '/api/development/portal/administration/emails';

    private const AUDIENCES = '/api/development/portal/administration/emails/audiences';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    /**
     * @param  list<string>  $rules
     * @return array{id: int, code: string, password: string}
     */
    private function share(array $rules): array
    {
        $code = bin2hex(random_bytes(12));
        $password = 'Guest-Pass-1';
        $id = (int) DB::connection('portal')->table('shared_pages')->insertGetId([
            'label' => 'Guest mail',
            'code' => $code,
            'password_hash' => Hash::make($password),
            'email' => null,
            'require_otp' => false,
            'expires_at' => now()->addDays(7),
            'rate_per_minute' => 60,
            'revoked_at' => null,
            'created_by' => 1,
        ]);
        foreach ($rules as $key) {
            DB::connection('portal')->table('shared_page_rules')->insert(['share_id' => $id, 'rule_key' => $key]);
        }

        return ['id' => $id, 'code' => $code, 'password' => $password];
    }

    private function guestToken(string $code, string $password): string
    {
        return (string) $this->postJson(self::SHARE.'/'.$code.'/access', ['password' => $password])
            ->assertOk()
            ->json('data.token');
    }

    public function test_a_guest_with_the_email_page_reads_the_composer_and_sends(): void
    {
        $share = $this->share(['email']);
        $token = $this->guestToken($share['code'], $share['password']);

        // The composer's first read answers a guest.
        $this->withToken($token)->getJson(self::AUDIENCES)->assertOk();

        // The preview is a read that rides a POST, so a guest gets it too.
        $this->withToken($token)->postJson(self::SEND.'/preview', [
            'body' => 'Hello from a guest.',
            'format' => 'markup',
            'footerId' => null,
        ])->assertOk();

        // The send is the one write: it lands in the outbox with no sender, and the guest's
        // other writes stay refused.
        $this->withToken($token)->postJson(self::SEND, [
            'category' => 'notice',
            'subject' => 'A note from the shared page',
            'body' => 'Hello from a guest.',
            'format' => 'markup',
            'audience' => 'custom',
            'emails' => ['someone@example.com'],
        ])->assertCreated();

        $row = DB::connection('portal')->table('email_messages')->where('subject', 'A note from the shared page')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->sent_by);

        $this->withToken($token)->postJson('/api/development/portal/administration/email-images', ['name' => 'x'])->assertForbidden();
    }

    public function test_a_guest_without_the_email_page_cannot_send(): void
    {
        $share = $this->share(['projects.view']);
        $token = $this->guestToken($share['code'], $share['password']);

        $this->withToken($token)->postJson(self::SEND, [
            'category' => 'notice',
            'subject' => 'Nope',
            'body' => 'Nope.',
            'format' => 'markup',
            'audience' => 'custom',
            'emails' => ['someone@example.com'],
        ])->assertForbidden();
    }
}
