<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalNameCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Edit details on Manage / Users (2026-10-20, user): a record's name, nickname, job title, work email,
 * location, employment status and start date, names written the company's way.
 */
class PortalManageUserDetailsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const USERS = '/api/development/portal/manage/users/';

    private const NATHANIEL = '220202-0002';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_a_head_admin_edits_someone_s_details_names_written_the_company_s_way(): void
    {
        $head = $this->employee("id_no = '".self::NATHANIEL."'");
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', (int) $member->getKey())->update(['email' => null, 'job_title' => null]);
        $session = $this->token($head);

        $profile = $this->withToken($session)->getJson(self::USERS.$member->getKey())->assertOk()->json('data');
        $this->assertTrue($profile['canEditDetails']);
        $this->assertContains('Regular', $profile['detailOptions']['employmentStatuses']);
        $this->assertIsArray($profile['detailOptions']['locations']);

        $saved = $this->withToken($session)->patchJson(self::USERS.$member->getKey().'/details', [
            'firstName' => '  JAYCRIS  ',
            'lastName' => 'pamintuan',
            'nickname' => 'CRIS',
            'jobTitle' => ' BIM Modeler (Architecture) ',
            'email' => ' JPamintuan@ikaikabim.com ',
            'location' => 'Laguna',
            'employmentStatus' => 'Regular',
            'startDate' => '2026-10-12',
        ])->assertOk()->json('data');

        $this->assertSame(['Jaycris', 'Pamintuan', 'CRIS'], [$saved['firstName'], $saved['lastName'], $saved['nickname']]);
        $this->assertSame(['BIM Modeler (Architecture)', 'jpamintuan@ikaikabim.com', 'Laguna', 'Regular', '2026-10-12'], [
            $saved['jobTitle'], $saved['email'], $saved['location'], $saved['employmentStatus'], $saved['startDate'],
        ]);
        $row = DB::connection('portal')->table('employees')->where('id', (int) $member->getKey())->first(['first_name', 'last_name', 'email']);
        $this->assertSame(['Jaycris', 'Pamintuan', 'jpamintuan@ikaikabim.com'], [$row->first_name, $row->last_name, $row->email]);

        // Another's email, an empty first name, a name with digits and an unknown status are refused.
        $taken = (string) DB::connection('portal')->table('employees')->where('id', '!=', (int) $member->getKey())->whereNotNull('email')->value('email');
        $this->withToken($session)->patchJson(self::USERS.$member->getKey().'/details', ['email' => strtoupper($taken)])
            ->assertStatus(422)->assertJsonPath('message', 'Someone already has that email address.');
        $this->withToken($session)->patchJson(self::USERS.$member->getKey().'/details', ['firstName' => '  '])->assertStatus(422);
        $this->withToken($session)->patchJson(self::USERS.$member->getKey().'/details', ['lastName' => 'Pamintuan2'])
            ->assertStatus(422)->assertJsonPath('message', 'Last name can only have letters, spaces, periods, apostrophes and hyphens.');
        $this->withToken($session)->patchJson(self::USERS.$member->getKey().'/details', ['employmentStatus' => 'Vacation'])->assertStatus(422);
    }

    public function test_without_the_power_the_details_stay_shut(): void
    {
        $member = $this->member();
        $other = $this->employee("LOWER(COALESCE(role, '')) != 'admin' AND id != ".(int) $member->getKey());
        $this->withToken($this->token($member))->patchJson(self::USERS.$other->getKey().'/details', ['firstName' => 'Someone'])->assertForbidden();
    }

    public function test_a_name_in_one_case_is_corrected_and_one_in_mixed_case_kept(): void
    {
        $this->assertSame('Jaycris Pamintuan', PortalNameCase::proper(' JAYCRIS   PAMINTUAN '));
        $this->assertSame('Dela Cruz-Santos', PortalNameCase::proper('dela cruz-santos'));
        $this->assertSame("O'Neil McDonald", PortalNameCase::proper("o'neil McDonald"));
        $this->assertSame('Jose Rizal III', PortalNameCase::proper('JOSE RIZAL iii'));
        $this->assertSame('Ñoño', PortalNameCase::proper('ÑOÑO'));
        $this->assertNull(PortalNameCase::proper('   '));
        $this->assertNull(PortalNameCase::proper(null));
    }

    private function employee(string $where): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
    }

    // Someone in the head admin's own department, so in their reach whatever it is.
    private function member(): Employee
    {
        return $this->employee("LOWER(COALESCE(role, '')) != 'admin' AND LOWER(COALESCE(department, '')) = 'mis' AND LOWER(COALESCE(role_level, '')) != 'executive'");
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }
}
