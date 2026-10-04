<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSignature;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Email signatures: a signature is a footer (email_blocks kind footer) whose body carries the
 * $SIGNATURE token, rendered with the employee's own details. A member reads their own on Settings;
 * the footers are edited with the email footers (the email.library power), and a footer saved as a
 * signature must carry the token.
 */
class PortalSignatureTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const SIGNATURE = '/api/development/portal/signature';

    private const BLOCKS = '/api/development/portal/administration/email-blocks';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    public function test_a_member_reads_their_own_signature_from_a_footer(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $member->forceFill(['phone_number' => '555-0100', 'location' => 'Cebu'])->save();

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE)->assertOk();
        $html = (string) $response->json('data.html');

        $this->assertSame('Company signature', $response->json('data.brand'));
        $this->assertStringContainsString($member->first_name, $html);
        // The details come from the full employee row, not the session columns the model carries.
        $this->assertStringContainsString('555-0100', $html);
        $this->assertStringContainsString('Cebu', $html);
    }

    public function test_a_footer_reads_for_the_person_when_it_rides_under_an_email(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $member->forceFill(['phone_number' => '555-0200'])->save();

        $filled = app(PortalSignature::class)
            ->fill($member, '<p>{name} - {phone}</p>', 'html');

        $this->assertStringContainsString($member->first_name, $filled);
        $this->assertStringContainsString('555-0200', $filled);
        $this->assertStringNotContainsString('{phone}', $filled);
    }

    public function test_a_member_downloads_their_signature_as_an_htm(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/document')->assertOk();

        $this->assertStringEndsWith('.htm', (string) $response->json('data.fileName'));
        $this->assertStringContainsString('<!doctype html>', (string) $response->json('data.html'));
    }

    public function test_a_member_downloads_a_one_click_outlook_installer(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/installer')->assertOk();

        $this->assertStringEndsWith('.cmd', (string) $response->json('data.fileName'));
        $script = (string) $response->json('data.script');
        $this->assertStringContainsString('@echo off', $script);
        // The signature travels base64-encoded, and the script sets it as the default for Outlook.
        $this->assertStringContainsString('IKAIKA_B64=', $script);
        $this->assertStringContainsString('NewSignature', $script);
    }

    public function test_it_builds_an_exchange_transport_rule_script_with_directory_tokens(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $response = $this->withToken($this->token($admin))->getJson(self::SIGNATURE.'/transport-rule')->assertOk();

        $this->assertStringEndsWith('.ps1', (string) $response->json('data.fileName'));
        $script = (string) $response->json('data.script');
        $this->assertStringContainsString('New-TransportRule', $script);
        $this->assertStringContainsString('-FromScope InOrganization', $script);
        // The person's fields become Active Directory tokens, so one rule covers everyone.
        $this->assertStringContainsString('%%DisplayName%%', $script);
        $this->assertStringNotContainsString('{name}', $script);
    }

    public function test_empty_placeholders_drop_their_separators(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $member->forceFill(['phone_number' => null, 'location' => null])->save();

        $footerId = (int) DB::connection('portal')->table('email_blocks')->insertGetId([
            'kind' => 'footer', 'name' => 'Tidy test', 'category' => null, 'subject' => null,
            'body' => '<p>{name} &middot; {phone} &middot; {location}</p>', 'format' => 'html',
            'created_by' => $member->getKey(), 'date_created' => now(),
        ]);
        DB::connection('portal')->table('employee_signatures')->insert(['employee_id' => $member->getKey(), 'footer_id' => $footerId]);

        $html = (string) $this->withToken($this->token($member))->getJson(self::SIGNATURE)->assertOk()->json('data.html');

        $this->assertStringContainsString($member->first_name, $html);
        $this->assertStringNotContainsString('&middot;', $html);
    }

    public function test_the_token_scan_ignores_style_blocks(): void
    {
        $this->assertFalse(PortalSignature::carriesSignature('<style>.x {name} {email}</style>'));
        $this->assertFalse(PortalSignature::carriesSignature('<p>No token here</p>'));
        $this->assertTrue(PortalSignature::carriesSignature('<p>{name}</p>'));
        $this->assertTrue(PortalSignature::carriesSignature('<p>$SIGNATURE</p>'));
    }

    public function test_the_editor_previews_the_wording(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $html = (string) $this->withToken($this->token($admin))
            ->postJson('/api/development/portal/signature/preview', ['body' => '<p>{name} - {email}</p>', 'format' => 'html'])
            ->assertOk()
            ->json('data.html');

        $this->assertStringContainsString($admin->first_name, $html);
    }

    public function test_a_signature_footer_must_carry_the_token(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);

        $this->withToken($token)->postJson(self::BLOCKS, [
            'kind' => 'footer', 'name' => 'No token', 'format' => 'html', 'body' => '<p>Plain</p>', 'signature' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'A signature footer needs a placeholder like {name} or $SIGNATURE.');

        $this->withToken($token)->postJson(self::BLOCKS, [
            'kind' => 'footer', 'name' => 'With token', 'format' => 'html', 'body' => '<p>$SIGNATURE</p>', 'signature' => true,
        ])->assertCreated();

        $this->assertTrue(DB::connection('portal')->table('email_blocks')->where('name', 'With token')->exists());
    }
}
