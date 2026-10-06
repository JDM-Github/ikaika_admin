<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmployeeSignature;
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
    protected array $connectionsToTransact = ['portal', 'core'];

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

    public function test_there_is_no_outlook_installer_any_more(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        // Settings copies the signature to paste into Outlook by hand; the .cmd installer was removed.
        $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/installer')->assertNotFound();
    }

    public function test_it_builds_one_exchange_rule_per_active_employee_from_their_portal_signature(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user' AND email LIKE '%@%'");
        $leaver = $this->employee("LOWER(COALESCE(role, '')) = 'user' AND email LIKE '%@%' AND id != ".(int) $member->getKey());
        $leaverEmail = strtolower((string) DB::connection('portal')->table('employees')->where('id', $leaver->getKey())->value('email'));
        $leaver->forceFill(['status' => 'Inactive'])->save();

        // The member's own footer is used, with a fixed sentence the rule can recognise in replies.
        $footerId = (int) DB::connection('portal')->table('email_blocks')->insertGetId([
            'kind' => 'footer', 'name' => 'Rule test', 'category' => null, 'subject' => null,
            'body' => "<p>{name} &middot; {phone}</p>\n<p>Rule test notice:   kept between ourselves, \u{00A9} IKAIKA. Second sentence.</p>",
            'format' => 'html', 'created_by' => $member->getKey(), 'date_created' => now(),
        ]);
        DB::connection('portal')->table('employee_signatures')->updateOrInsert(['employee_id' => $member->getKey()], ['footer_id' => $footerId]);
        $memberEmail = strtolower((string) app(PortalEmployeeSignature::class)->values($member)['email']);

        $response = $this->withToken($this->token($admin))->getJson(self::SIGNATURE.'/transport-rule')->assertOk();

        $this->assertStringEndsWith('.ps1', (string) $response->json('data.fileName'));
        $script = (string) $response->json('data.script');
        // Each person's rule carries their rendered signature; no Microsoft 365 profile tokens.
        $this->assertStringContainsString("Email = '".$memberEmail."'", $script);
        $this->assertStringContainsString("Name = 'IKAIKA signature #".$member->getKey().' (', $script);
        $this->assertStringContainsString('Rule test notice: kept between ourselves, &#169; IKAIKA.', $script);
        $this->assertStringContainsString("Marker = 'Rule test notice: kept between ourselves'", $script);
        $this->assertStringNotContainsString('%%', $script);
        $this->assertStringNotContainsString('{name}', $script);
        $this->assertSame(0, preg_match('/[\x80-\xFF]/', $script), 'The script stays ASCII for Windows PowerShell.');
        // A leaver gets no rule, and the old single rule and stale per-person rules are removed.
        $this->assertStringNotContainsString("Email = '".$leaverEmail."'", $script);
        $this->assertStringContainsString("-eq 'IKAIKA email signature'", $script);
        $this->assertStringContainsString("-like 'IKAIKA signature #*'", $script);
        // Invites are skipped, an unchangeable message goes out as is, and the window waits to be read.
        $this->assertStringContainsString("ExceptIfMessageTypeMatches = 'Calendaring'", $script);
        $this->assertStringContainsString("ApplyHtmlDisclaimerFallbackAction = 'Ignore'", $script);
        $this->assertStringContainsString('Exchange Administrator or Global Administrator role', $script);
        $this->assertStringContainsString("Read-Host 'Press Enter to close'", $script);
    }

    public function test_it_lists_who_the_rules_can_cover_and_builds_the_script_for_only_the_chosen_people(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user' AND email LIKE '%@%'");
        $other = $this->employee("LOWER(COALESCE(role, '')) = 'user' AND email LIKE '%@%' AND id != ".(int) $member->getKey());
        $signatures = app(PortalEmployeeSignature::class);
        $memberEmail = strtolower((string) $signatures->values($member)['email']);
        $otherEmail = strtolower((string) $signatures->values($other)['email']);
        $token = $this->token($admin);

        $people = $this->withToken($token)->getJson(self::SIGNATURE.'/transport-rule/people')->assertOk()->json('data');

        $ids = array_column($people, 'id');
        $this->assertContains((string) $member->getKey(), $ids);
        $row = $people[array_search((string) $member->getKey(), $ids, true)];
        $this->assertSame($memberEmail, $row['email']);
        $this->assertNotSame('', $row['footer']);

        $script = (string) $this->withToken($token)
            ->getJson(self::SIGNATURE.'/transport-rule?employeeIds='.$member->getKey())
            ->assertOk()->json('data.script');

        $this->assertStringContainsString("Email = '".$memberEmail."'", $script);
        $this->assertStringNotContainsString("Email = '".$otherEmail."'", $script);
        $this->assertStringContainsString('# People in this file: 1.', $script);
        // Windows PowerShell 5.1 gets the NuGet provider without stopping to ask.
        $this->assertStringContainsString('Install-PackageProvider -Name NuGet', $script);
        $this->withToken($token)->getJson(self::SIGNATURE.'/transport-rule?employeeIds=')->assertStatus(422);
        $this->withToken($token)->getJson(self::SIGNATURE.'/transport-rule?employeeIds=999999999')->assertNotFound();
        $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/transport-rule/people')->assertForbidden();
    }

    public function test_it_builds_a_script_that_removes_every_signature_rule(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $response = $this->withToken($this->token($admin))->getJson(self::SIGNATURE.'/transport-rule/remover')->assertOk();

        $this->assertSame('Remove-IKAIKA-Signature-Rules.ps1', $response->json('data.fileName'));
        $script = (string) $response->json('data.script');
        // Only the signature's own rules: the per-person prefix and the old single rule.
        $this->assertStringContainsString('-like \'IKAIKA signature #*\' -or $_.Name -eq \'IKAIKA email signature\'', $script);
        $this->assertStringContainsString('Remove-TransportRule', $script);
        $this->assertStringNotContainsString('New-TransportRule -Name', $script);
        $this->assertStringContainsString("Read-Host 'Press Enter to close'", $script);
        $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/transport-rule/remover')->assertForbidden();
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

    public function test_an_admin_chooses_the_default_signature_footer(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        DB::connection('portal')->table('employee_signatures')->where('employee_id', $member->getKey())->update(['footer_id' => null]);
        $footerId = $this->footer('Default test', '<p>Default test {name}</p>', $admin);
        $adminToken = $this->token($admin);
        $memberToken = $this->token($member);

        $response = $this->withToken($adminToken)->putJson(self::SIGNATURE.'/default', ['footerId' => $footerId])->assertOk();

        $this->assertSame((string) $footerId, $response->json('data.footerId'));
        $log = DB::connection('portal')->table('logs')->orderByDesc('id')->first(['employee_id', 'record_id', 'message']);
        $this->assertSame((int) $admin->getKey(), (int) $log->employee_id);
        $this->assertSame((string) $footerId, (string) $log->record_id);
        $this->assertStringContainsString('the default signature', (string) $log->message);
        $this->withToken($adminToken)->getJson(self::SIGNATURE.'/default')->assertOk()->assertJsonPath('data.footerId', (string) $footerId);
        // Anyone without their own footer now gets the default, on Settings and in the Exchange rules.
        $signature = $this->withToken($memberToken)->getJson(self::SIGNATURE)->assertOk();
        $this->assertSame((string) $footerId, $signature->json('data.footerId'));
        $this->assertStringContainsString('Default test', (string) $signature->json('data.html'));
        $script = (string) $this->withToken($adminToken)->getJson(self::SIGNATURE.'/transport-rule')->assertOk()->json('data.script');
        $this->assertStringContainsString('Default test', $script);
    }

    public function test_only_a_signature_footer_can_be_the_default(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $plainId = $this->footer('Plain test', '<p>No placeholders here</p>', $admin);

        $this->withToken($this->token($admin))->putJson(self::SIGNATURE.'/default', ['footerId' => $plainId])->assertStatus(422);
        $this->withToken($this->token($admin))->putJson(self::SIGNATURE.'/default', ['footerId' => 999999999])->assertNotFound();
    }

    public function test_a_removed_default_falls_back_to_the_oldest_signature_footer(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        DB::connection('core')->table('settings')->where('product', 'portal')->where('setting_key', 'signature.default_footer')->delete();
        $oldest = $this->withToken($this->token($admin))->getJson(self::SIGNATURE.'/default')->assertOk()->json('data.footerId');
        $footerId = $this->footer('Short lived', '<p>{name}</p>', $admin);
        $this->withToken($this->token($admin))->putJson(self::SIGNATURE.'/default', ['footerId' => $footerId])->assertOk();

        DB::connection('portal')->table('email_blocks')->where('id', $footerId)->delete();

        $this->withToken($this->token($admin))->getJson(self::SIGNATURE.'/default')->assertOk()->assertJsonPath('data.footerId', $oldest);
    }

    public function test_a_member_cannot_choose_the_default(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $this->withToken($this->token($member))->putJson(self::SIGNATURE.'/default', ['footerId' => 1])->assertForbidden();
    }

    private function footer(string $name, string $body, Employee $by): int
    {
        return (int) DB::connection('portal')->table('email_blocks')->insertGetId([
            'kind' => 'footer', 'name' => $name, 'category' => null, 'subject' => null,
            'body' => $body, 'format' => 'html', 'created_by' => $by->getKey(), 'date_created' => now(),
        ]);
    }
}
