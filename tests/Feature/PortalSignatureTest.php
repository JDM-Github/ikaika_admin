<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Email signatures: a member reads their own rendered signature (Settings); the templates are edited
 * behind the signature.template power. The signature is rendered in code from the template plus the
 * employee's details, so the format is locked.
 */
class PortalSignatureTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const SIGNATURE = '/api/development/portal/signature';

    private const TEMPLATES = '/api/development/portal/manage/signature-templates';

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

    public function test_a_member_reads_their_own_signature(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $response = $this->withToken($this->token($member))->getJson(self::SIGNATURE)->assertOk();

        $this->assertSame('IKAIKA', $response->json('data.brand'));
        $this->assertStringContainsString($member->first_name, (string) $response->json('data.html'));
    }

    public function test_the_templates_are_edited_behind_the_power(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");

        $this->withToken($this->token($member))->getJson(self::TEMPLATES)->assertForbidden();

        $adminToken = $this->token($admin);
        $list = $this->withToken($adminToken)->getJson(self::TEMPLATES)->assertOk()->json('data.templates');
        $this->assertSame('IKAIKA', $list[0]['brand']);

        $added = $this->withToken($adminToken)->postJson(self::TEMPLATES, ['brand' => 'SVM', 'companyName' => 'SVM Inc', 'website' => 'svm.com'])->assertOk();
        $row = collect($added->json('data.templates'))->firstWhere('brand', 'SVM');
        $this->assertNotNull($row);
        $this->assertFalse($row['isDefault']);

        $this->withToken($adminToken)->postJson(self::TEMPLATES, ['brand' => 'svm', 'companyName' => 'Other'])->assertStatus(422);

        $this->withToken($adminToken)->patchJson(self::TEMPLATES.'/'.$row['id'], ['brand' => 'SVM', 'companyName' => 'SVM Services'])->assertOk();
        $this->assertSame('SVM Services', DB::connection('portal')->table('signature_templates')->where('id', $row['id'])->value('company_name'));

        $this->withToken($adminToken)->deleteJson(self::TEMPLATES.'/'.$row['id'])->assertOk();
        $this->withToken($adminToken)->deleteJson(self::TEMPLATES.'/'.$row['id'])->assertNotFound();
    }
}
