<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
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

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE)->assertOk();

        $this->assertSame('Company signature', $response->json('data.brand'));
        $this->assertStringContainsString($member->first_name, (string) $response->json('data.html'));
    }

    public function test_a_member_downloads_their_signature_as_an_htm(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE.'/document')->assertOk();

        $this->assertStringEndsWith('.htm', (string) $response->json('data.fileName'));
        $this->assertStringContainsString('<!doctype html>', (string) $response->json('data.html'));
    }

    public function test_a_signature_footer_must_carry_the_token(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);

        $this->withToken($token)->postJson(self::BLOCKS, [
            'kind' => 'footer', 'name' => 'No token', 'format' => 'html', 'body' => '<p>Plain</p>', 'signature' => true,
        ])->assertStatus(422)->assertJsonPath('message', 'A signature footer needs the $SIGNATURE token.');

        $this->withToken($token)->postJson(self::BLOCKS, [
            'kind' => 'footer', 'name' => 'With token', 'format' => 'html', 'body' => '<p>$SIGNATURE</p>', 'signature' => true,
        ])->assertCreated();

        $this->assertTrue(DB::connection('portal')->table('email_blocks')->where('name', 'With token')->exists());
    }
}
