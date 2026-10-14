<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalNotificationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Profile changes (2026-10-14, user): anyone changes their own employee information and documents on
 * Settings, nothing reaches their record until a head admin approves it, and the head admins are told.
 */
class PortalProfileChangesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const MINE = '/api/development/portal/user/information';

    private const CHANGES = '/api/development/portal/manage/users/changes';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private const NATHANIEL = '220202-0002';

    private const STEVEN = '220302-0003';

    /**
     * @var list<string>
     */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'services.cloudinary.cloud_name' => 'demo-cloud',
            'services.cloudinary.api_key' => 'key123',
            'services.cloudinary.api_secret' => 'secret456',
        ]);
        $uploads = 0;
        Http::fake([
            'https://api.cloudinary.com/v1_1/demo-cloud/auto/upload' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['secure_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/asked'.$uploads.'.pdf']);
            },
            '*' => Http::response([], 200),
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    // The reported ask (2026-10-14): a change to their own information waits for a head admin, who is told.
    public function test_changes_someone_asks_for_reach_their_record_only_once_a_head_admin_approves(): void
    {
        $member = $this->member();
        $kept = $this->attachment($member, 'tax_identification');
        $before = $this->record($member);
        $session = $this->token($member);

        $mine = $this->withToken($session)->getJson(self::MINE)->assertOk()->json('data');
        $this->assertArrayHasKey('bloodType', $mine['information']);
        $this->assertNull($mine['pending']);

        $asked = $this->withToken($session)->post(self::MINE.'/changes', [
            'bloodType' => 'AB+',
            'phoneNumber' => '09171234567',
            'files' => ['ss_id' => $this->upload('sss.pdf')],
            'removeDocumentIds' => [$kept],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.pending');
        $this->assertSame('Pending', $asked['status']);
        $this->assertContains(['key' => 'bloodType', 'from' => $before['blood_type'], 'to' => 'AB+'], $asked['fields']);
        $this->assertSame('ss_id', $asked['documents']['added'][0]['field']);
        $this->assertStringContainsString('/upload/pg_{page},f_jpg', (string) $asked['documents']['added'][0]['pagesUrl']);
        $this->assertSame((string) $kept, $asked['documents']['removed'][0]['id']);

        // Nothing on their record moved, and one request waits at a time.
        $this->assertSame($before, $this->record($member));
        $this->assertTrue(DB::connection('portal')->table('attachments')->where('id', $kept)->exists());
        $this->assertSame(0, $this->files($member, 'ss_id'));
        $this->withToken($session)->post(self::MINE.'/changes', ['bloodType' => 'O+'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Your earlier changes are still waiting for approval. Withdraw them to change something else.');

        // Both head admins are told, and no one else may see what waits.
        $head = $this->employee("id_no = '".self::NATHANIEL."'");
        $notice = $this->notice($head, PortalNotificationType::PROFILE_CHANGES_TO_REVIEW, $asked['id']);
        $this->assertSame('/manage/user-management', $notice->link_path);
        $this->assertNotNull($this->notice($this->employee("id_no = '".self::STEVEN."'"), PortalNotificationType::PROFILE_CHANGES_TO_REVIEW, $asked['id']));
        $this->withToken($this->token($this->plainAdmin()))->getJson(self::CHANGES)->assertForbidden();

        $headSession = $this->token($head);
        $waiting = collect($this->withToken($headSession)->getJson(self::CHANGES)->assertOk()->json('data.items'))->firstWhere('id', $asked['id']);
        $this->assertSame((string) $member->getKey(), $waiting['employee']['id']);

        $this->withToken($headSession)->patchJson(self::CHANGES.'/'.$asked['id'], ['decision' => 'approve'])->assertOk();
        $after = $this->record($member);
        $this->assertSame('AB+', $after['blood_type']);
        $this->assertSame('09171234567', $after['phone_number']);
        $this->assertFalse(DB::connection('portal')->table('attachments')->where('id', $kept)->exists());
        $this->assertSame(1, $this->files($member, 'ss_id'));
        $this->assertSame('/settings', $this->notice($member, PortalNotificationType::PROFILE_CHANGES_APPROVED, $asked['id'])->link_path);

        $mine = $this->withToken($session)->getJson(self::MINE)->assertOk()->json('data');
        $this->assertNull($mine['pending']);
        $this->assertSame('Approved', $mine['lastDecision']['status']);
        $this->withToken($headSession)->patchJson(self::CHANGES.'/'.$asked['id'], ['decision' => 'reject', 'remarks' => 'Late'])
            ->assertStatus(422)->assertJsonPath('message', 'Those profile changes were already approved.');
    }

    public function test_a_rejection_says_why_and_leaves_their_record_as_it_was(): void
    {
        $member = $this->member();
        $before = $this->record($member);
        $session = $this->token($member);
        $id = $this->withToken($session)->post(self::MINE.'/changes', ['bloodType' => 'AB+'], ['Accept' => 'application/json'])->assertCreated()->json('data.pending.id');
        $headSession = $this->token($this->employee("id_no = '".self::NATHANIEL."'"));

        $this->withToken($headSession)->patchJson(self::CHANGES.'/'.$id, ['decision' => 'reject'])
            ->assertStatus(422)->assertJsonValidationErrors(['remarks' => 'Say why the changes are rejected.']);
        $this->withToken($headSession)->patchJson(self::CHANGES.'/'.$id, ['decision' => 'reject', 'remarks' => 'Blood type needs a lab result.'])->assertOk();

        $this->assertSame($before, $this->record($member));
        $this->assertStringContainsString('Blood type needs a lab result.', (string) $this->notice($member, PortalNotificationType::PROFILE_CHANGES_REJECTED, $id)->message);
        $mine = $this->withToken($session)->getJson(self::MINE)->assertOk()->json('data');
        $this->assertSame(['status' => 'Rejected', 'remarks' => 'Blood type needs a lab result.'], array_intersect_key($mine['lastDecision'], ['status' => 1, 'remarks' => 1]));

        // They may ask again, and the old rejection gives way to the new request.
        $this->withToken($session)->post(self::MINE.'/changes', ['bloodType' => 'B+'], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.lastDecision', null);
    }

    public function test_withdrawn_changes_let_them_ask_again_and_can_no_longer_be_decided(): void
    {
        $member = $this->member();
        $session = $this->token($member);
        $this->withToken($session)->deleteJson(self::MINE.'/changes')->assertNotFound();
        $id = $this->withToken($session)->post(self::MINE.'/changes', ['bloodType' => 'AB+'], ['Accept' => 'application/json'])->assertCreated()->json('data.pending.id');

        $this->withToken($session)->deleteJson(self::MINE.'/changes')->assertOk()->assertJsonPath('data.pending', null);
        $this->withToken($this->token($this->employee("id_no = '".self::NATHANIEL."'")))->patchJson(self::CHANGES.'/'.$id, ['decision' => 'approve'])
            ->assertStatus(422)->assertJsonPath('message', 'Those profile changes were already withdrawn.');
        $this->withToken($session)->post(self::MINE.'/changes', ['bloodType' => 'AB+'], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_an_empty_or_foreign_change_is_refused_and_a_head_admin_does_not_approve_their_own(): void
    {
        $member = $this->member();
        $session = $this->token($member);
        $this->withToken($session)->post(self::MINE.'/changes', [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Change at least one detail or document.');
        $theirs = $this->attachment($this->plainAdmin(), 'tax_identification');
        $this->withToken($session)->post(self::MINE.'/changes', ['removeDocumentIds' => [$theirs]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'One of the documents to take off is not yours, or is gone.');
        $this->withToken($session)->post(self::MINE.'/changes', ['files' => ['signature' => $this->upload('signature.pdf')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'The signature has to be a JPG, PNG, WebP or GIF picture.');

        // A head admin's own change waits for the other head admin, who alone is told.
        $nathaniel = $this->employee("id_no = '".self::NATHANIEL."'");
        $steven = $this->employee("id_no = '".self::STEVEN."'");
        $id = $this->withToken($this->token($nathaniel))->post(self::MINE.'/changes', ['bloodType' => 'AB+'], ['Accept' => 'application/json'])->assertCreated()->json('data.pending.id');
        $this->assertNull($this->notice($nathaniel, PortalNotificationType::PROFILE_CHANGES_TO_REVIEW, $id));
        $this->assertNotNull($this->notice($steven, PortalNotificationType::PROFILE_CHANGES_TO_REVIEW, $id));
        $this->withToken($this->token($nathaniel))->patchJson(self::CHANGES.'/'.$id, ['decision' => 'approve'])
            ->assertForbidden()->assertJsonPath('message', 'Someone else approves changes to your own information.');
        $this->withToken($this->token($steven))->patchJson(self::CHANGES.'/'.$id, ['decision' => 'approve'])->assertOk();
        $this->assertSame('AB+', $this->record($nathaniel)['blood_type']);
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

    // Anyone may ask: a member who is no head admin.
    private function member(): Employee
    {
        return $this->employee("LOWER(COALESCE(role, '')) != 'admin' AND id_no NOT IN ('".self::NATHANIEL."', '".self::STEVEN."')");
    }

    private function plainAdmin(): Employee
    {
        return $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive' AND id_no NOT IN ('".self::NATHANIEL."', '".self::STEVEN."')");
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    /**
     * @return array{blood_type: mixed, phone_number: mixed}
     */
    private function record(Employee $employee): array
    {
        $row = DB::connection('portal')->table('employees')->where('id', (int) $employee->getKey())->first(['blood_type', 'phone_number']);

        return ['blood_type' => $row?->blood_type, 'phone_number' => $row?->phone_number];
    }

    private function attachment(Employee $employee, string $field): int
    {
        return (int) DB::connection('portal')->table('attachments')->insertGetId([
            'table_name' => 'employees', 'record_id' => (int) $employee->getKey(), 'field_name' => $field,
            'file_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/kept.pdf',
            'file_name' => 'kept.pdf', 'file_size_bytes' => 900, 'mime_type' => 'application/pdf',
        ]);
    }

    private function files(Employee $employee, string $field): int
    {
        return DB::connection('portal')->table('attachments')->where('table_name', 'employees')->where('record_id', (int) $employee->getKey())->where('field_name', $field)->count();
    }

    private function notice(Employee $recipient, string $type, string $recordId): ?object
    {
        return DB::connection('portal')->table('notifications')->where('employee_id', (int) $recipient->getKey())->where('type', $type)
            ->where('payload->recordId', $recordId)->orderByDesc('id')->first();
    }

    private function upload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'change-');
        file_put_contents($path, self::PDF);
        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
