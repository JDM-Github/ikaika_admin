<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manage / Users Edit profiles: the portal's own copy of an employee's signature details, kept apart
 * from `employees` because the Airtable sync overwrites that row. A field left blank falls back to the
 * employee row on read.
 */
class PortalManageUserProfileTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const BASE = '/api/development/portal/manage/users';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function employee(string $where, int $skip = 0): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->skip($skip)
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    public function test_an_admin_edits_the_signature_profile_and_blank_fields_fall_back(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $member->forceFill(['department' => $admin->department])->save();
        $token = $this->token($admin);

        $this->withToken($token)->getJson(self::BASE.'/'.$member->id)
            ->assertOk()
            ->assertJsonPath('data.canEditProfile', true);

        $this->withToken($token)->patchJson(self::BASE.'/'.$member->id.'/profile', [
            'title' => 'Mechanical Engineer',
            'email' => 'signature@ikaikabim.com',
            'phoneNumber' => '555-0100',
            'department' => $admin->department,
            'location' => 'Cebu',
        ])->assertOk()->assertJsonPath('data.signature.email', 'signature@ikaikabim.com');

        $row = DB::connection('portal')->table('employee_signatures')->where('employee_id', $member->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('555-0100', $row->phone_number);

        // Clearing a field stores null, so the read falls back to the employee row.
        $this->withToken($token)->patchJson(self::BASE.'/'.$member->id.'/profile', [
            'title' => null,
            'email' => 'signature@ikaikabim.com',
            'phoneNumber' => null,
            'department' => null,
            'location' => null,
        ])->assertOk()->assertJsonPath('data.signature.title', $member->job_title);
    }

    public function test_a_member_cannot_edit_a_profile(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $other = $this->employee("LOWER(COALESCE(role, '')) = 'user'", 1);

        $this->withToken($this->token($member))
            ->patchJson(self::BASE.'/'.$other->id.'/profile', ['title' => 'Hacker'])
            ->assertForbidden();
    }
}
