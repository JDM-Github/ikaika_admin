<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalLetters;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Email sign-in (2026-10-12): a code to every address on record, all of them typed back.
 */
class PortalEmailLoginTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/auth';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    private function employee(?string $personal, string $status = 'Active'): int
    {
        return (int) DB::connection('portal')->table('employees')->insertGetId([
            'first_name' => 'Kelly',
            'last_name' => 'Login Test',
            'email' => 'kelly.login@example.test',
            'personal_email' => $personal,
            'status' => $status,
            'role' => 'user',
            'department' => 'Engineering',
        ]);
    }

    // The code a letter carries, by the address it went to.
    private function codeSentTo(string $address): string
    {
        $mail = Mail::sent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo($address))->last();
        $this->assertNotNull($mail);
        preg_match('/\*\*(\d{6})\*\*/', $mail->body, $match);

        return $match[1];
    }

    public function test_both_addresses_get_their_own_code_and_both_must_be_typed(): void
    {
        $id = $this->employee('kelly.home@example.test');

        // The personal address starts it just as well; the answer names both, masked.
        $this->postJson(self::BASE.'/email/start', ['email' => ' Kelly.Home@Example.test '])
            ->assertOk()
            ->assertJsonPath('targets.0.kind', 'work')
            ->assertJsonPath('targets.0.hint', 'ke***@example.test')
            ->assertJsonPath('targets.1.kind', 'personal');
        Mail::assertSent(PortalEmailMail::class, 2);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->bodyFormat === PortalEmailMarkup::FORMAT_LETTER
            && str_contains($mail->body, 'A second code went to your'));
        $work = $this->codeSentTo('kelly.login@example.test');
        $personal = $this->codeSentTo('kelly.home@example.test');

        // One code alone, or the two swapped, does not sign in; each miss counts.
        $this->postJson(self::BASE.'/email/verify', ['email' => 'kelly.login@example.test', 'codes' => ['work' => $work]])
            ->assertStatus(422)->assertJsonPath('message', 'One of the codes is not right.');
        $this->assertSame(1, (int) DB::connection('portal')->table('login_otps')->where('employee_id', $id)->value('attempts'));

        $this->postJson(self::BASE.'/email/verify', ['email' => 'kelly.login@example.test', 'codes' => ['work' => $work, 'personal' => $personal]])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'employee']);
        $this->assertNull(DB::connection('portal')->table('login_otps')->where('employee_id', $id)->first());
    }

    public function test_one_address_on_record_means_one_code(): void
    {
        $this->employee(null);

        $this->postJson(self::BASE.'/email/start', ['email' => 'kelly.login@example.test'])
            ->assertOk()
            ->assertJsonCount(1, 'targets')
            ->assertJsonPath('targets.0.kind', 'work');
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => ! str_contains($mail->body, 'second code'));

        $this->postJson(self::BASE.'/email/verify', [
            'email' => 'kelly.login@example.test',
            'codes' => ['work' => $this->codeSentTo('kelly.login@example.test')],
        ])->assertOk();
    }

    public function test_unknown_and_inactive_read_alike_and_a_quick_second_start_waits(): void
    {
        $this->postJson(self::BASE.'/email/start', ['email' => 'nobody@example.test'])
            ->assertStatus(422)->assertJsonPath('message', 'No active portal account uses that email.');

        DB::connection('portal')->table('employees')->insert([
            'first_name' => 'Gone', 'last_name' => 'Login Test', 'email' => 'gone.login@example.test', 'status' => 'Inactive', 'role' => 'user',
        ]);
        $this->postJson(self::BASE.'/email/start', ['email' => 'gone.login@example.test'])
            ->assertStatus(422)->assertJsonPath('message', 'No active portal account uses that email.');
        $this->postJson(self::BASE.'/email/start', ['email' => 'not an email'])->assertStatus(422);
        Mail::assertNothingSent();

        $this->employee(null);
        $this->postJson(self::BASE.'/email/start', ['email' => 'kelly.login@example.test'])->assertOk();
        $this->postJson(self::BASE.'/email/start', ['email' => 'kelly.login@example.test'])->assertStatus(429);
    }

    public function test_expired_or_exhausted_codes_ask_for_new_ones(): void
    {
        $id = $this->employee(null);
        $this->postJson(self::BASE.'/email/start', ['email' => 'kelly.login@example.test'])->assertOk();
        $code = $this->codeSentTo('kelly.login@example.test');

        DB::connection('portal')->table('login_otps')->where('employee_id', $id)->update(['attempts' => 5]);
        $this->postJson(self::BASE.'/email/verify', ['email' => 'kelly.login@example.test', 'codes' => ['work' => $code]])
            ->assertStatus(422)->assertJsonPath('message', 'Too many tries. Ask for new codes.');

        DB::connection('portal')->table('login_otps')->where('employee_id', $id)->update(['attempts' => 0, 'expires_at' => now()->subMinute()]);
        $this->postJson(self::BASE.'/email/verify', ['email' => 'kelly.login@example.test', 'codes' => ['work' => $code]])
            ->assertStatus(422)->assertJsonPath('message', 'Ask for new codes.');
    }

    // Each letter is a relay round trip; it waits for the answer, so two codes never hold the screen.
    public function test_a_letter_goes_out_after_the_answer_and_a_failed_one_is_only_logged(): void
    {
        PortalLetters::send('kelly.login@example.test', 'Subject', 'Body');
        Mail::assertNothingSent();

        app(DeferredCallbackCollection::class)->invoke();
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('kelly.login@example.test'));

        Mail::shouldReceive('to')->andThrow(new RuntimeException('relay down'));
        Log::spy();
        PortalLetters::send('kelly.login@example.test', 'Subject', 'Body');
        app(DeferredCallbackCollection::class)->invoke();
        Log::shouldHaveReceived('warning')->with('portal.letter.failed', ['subject' => 'Subject', 'reason' => 'relay down']);
    }

    // ID-number sign-in is for local development and the suite; staging and production answer 404.
    public function test_id_number_sign_in_is_closed_outside_local(): void
    {
        $this->app['env'] = 'staging';
        $this->postJson(self::BASE.'/login', ['id_no' => '260701-0020'])->assertNotFound();
        $this->app['env'] = 'testing';
    }
}
