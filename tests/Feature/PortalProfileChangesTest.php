<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalNotificationType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Profile changes (2026-10-14, user): anyone changes their own employee information and documents on
 * Profile, nothing reaches their record until a head admin approves it, and the head admins are told.
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

    /**
     * Nominatim's address at the pin.
     *
     * @var array<string, string>
     */
    private array $atPin = [];

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
            // Where an approved address is placed on the map (2026-10-19).
            // What sits at a pin, for Use my pin (2026-10-19).
            'https://nominatim.openstreetmap.org/*' => fn (ClientRequest $request) => str_contains($request->url(), '/reverse')
                ? Http::response(['address' => $this->atPin])
                : Http::response([['lat' => '14.2117', 'lon' => '121.1653']]),
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
        $this->assertSame('/profile', $this->notice($member, PortalNotificationType::PROFILE_CHANGES_APPROVED, $asked['id'])->link_path);

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

    // The reported ask (2026-10-19): their profile warns while a detail or a document a registration needs is missing.
    public function test_their_information_names_what_a_registration_needs_and_they_have_not_got(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', (int) $member->getKey())->update(['blood_type' => null, 'personal_email' => null, 'phone_number' => '09171234567']);
        DB::connection('portal')->table('attachments')->where('table_name', 'employees')->where('record_id', (int) $member->getKey())->whereIn('field_name', ['signature', 'national_id'])->delete();
        $this->attachment($member, 'tax_identification');

        $missing = $this->withToken($this->token($member))->getJson(self::MINE)->assertOk()->json('data.missing');

        $this->assertContains('bloodType', $missing['fields']);
        $this->assertContains('signature', $missing['documents']);
        // The personal email and the national ID are never asked for; what they have is not named.
        $this->assertNotContains('personalEmail', $missing['fields']);
        $this->assertNotContains('phoneNumber', $missing['fields']);
        $this->assertNotContains('national_id', $missing['documents']);
        $this->assertNotContains('tax_identification', $missing['documents']);
    }

    // The reported ask (2026-10-19): Profile edits both addresses in parts, as the registration asks for them.
    public function test_addresses_are_read_back_into_their_places_and_sent_in_parts(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employees')->where('id', (int) $member->getKey())->update([
            'address' => '377 Chipeco Avenue, Barangay Lecheria, Calamba City, Laguna',
            'emergency_contact_address' => null,
        ]);
        // The pickers' lists are theirs to read once signed in, and no one else's.
        $this->getJson('/api/development/portal/user/places/provinces')->assertUnauthorized();
        $session = $this->token($member);

        $places = $this->withToken($session)->getJson(self::MINE)->assertOk()->json('data.addressPlaces');
        $this->assertSame(['provinceCode' => '043400000', 'cityCode' => '043405000', 'barangayCode' => '043405020'], $places['address']);
        $this->assertNull($places['emergencyContactAddress']);

        $this->assertContains(['code' => '043400000', 'name' => 'Laguna'], $this->withToken($session)->getJson('/api/development/portal/user/places/provinces')->assertOk()->json('data'));
        $this->assertContains('City of Calamba', array_column($this->withToken($session)->getJson('/api/development/portal/user/places/provinces/043400000/cities')->assertOk()->json('data'), 'name'));
        $this->assertContains('Lecheria', array_column($this->withToken($session)->getJson('/api/development/portal/user/places/cities/043405000/barangays')->assertOk()->json('data'), 'name'));

        // A part left out is refused, and so is a city outside the province picked.
        $this->withToken($session)->post(self::MINE.'/changes', ['addressCountry' => 'Philippines', 'addressProvinceCode' => '043400000'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'Finish your address: every part down to the house number and street.');
        $this->withToken($session)->post(self::MINE.'/changes', [
            'emergencyContactCountry' => 'Philippines', 'emergencyContactProvinceCode' => '043400000', 'emergencyContactCityCode' => '012801000',
            'emergencyContactBarangayCode' => '043405020', 'emergencyContactStreet' => '12 Rizal Street',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('message', 'Choose the city or municipality again; it is not in that province.');

        $pending = $this->withToken($session)->post(self::MINE.'/changes', [
            'addressCountry' => 'Philippines', 'addressProvinceCode' => '043400000', 'addressCityCode' => '043405000',
            'addressBarangayCode' => '043405020', 'addressStreet' => '12 Rizal Street',
            'emergencyContactCountry' => 'Japan', 'emergencyContactProvince' => 'Tokyo', 'emergencyContactCity' => 'Shinjuku',
            'emergencyContactStreet' => '3-1 Nishi-Shinjuku',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.pending');
        $asked = $pending['fields'];

        $this->assertContains(['key' => 'address', 'from' => '377 Chipeco Avenue, Barangay Lecheria, Calamba City, Laguna', 'to' => '12 Rizal Street, Lecheria, City of Calamba, Laguna, Philippines'], $asked);
        $this->assertContains(['key' => 'emergencyContactAddress', 'from' => null, 'to' => '3-1 Nishi-Shinjuku, Shinjuku, Tokyo, Japan'], $asked);

        // The reported ask (2026-10-19): once approved, their home on the map moves to the new address, and stays theirs.
        $homes = DB::connection('portal')->table('employee_home_locations');
        $homes->where('employee_id', (int) $member->getKey())->delete();
        DB::connection('portal')->table('employee_home_locations')->insert([
            'employee_id' => (int) $member->getKey(), 'query' => 'map', 'source' => 'map', 'latitude' => 10.5, 'longitude' => 122.5, 'geocoded_at' => now(),
        ]);
        $this->withToken($this->token($this->employee("id_no = '".self::NATHANIEL."'")))->patchJson(self::CHANGES.'/'.$pending['id'], ['decision' => 'approve'])->assertOk();

        $home = DB::connection('portal')->table('employee_home_locations')->where('employee_id', (int) $member->getKey())->first();
        $this->assertSame('approved', $home->source);
        $this->assertEqualsWithDelta(14.2117, (float) $home->latitude, 0.0001);
        $this->assertEqualsWithDelta(121.1653, (float) $home->longitude, 0.0001);
        $this->assertEqualsWithDelta(14.2117, (float) $home->suggested_latitude, 0.0001);
        $this->assertStringContainsString('Your home on the map moved to your new address.', (string) $this->notice($member, PortalNotificationType::PROFILE_CHANGES_APPROVED, $pending['id'])->message);
        $mine = $this->withToken($this->token($member))->getJson('/api/development/portal/user/home')->assertOk()->json('data');
        $this->assertSame(['latitude' => 14.2117, 'longitude' => 121.1653, 'method' => 'approved'], $mine['home']);
        $this->assertFalse($mine['required']);
    }

    // The reported ask (2026-10-19): Use my pin fills the address in from what sits at their pin.
    public function test_the_address_at_their_pin_is_read_into_its_places(): void
    {
        $member = $this->member();
        DB::connection('portal')->table('employee_home_locations')->where('employee_id', (int) $member->getKey())->delete();
        $session = $this->token($member);
        $this->withToken($session)->getJson('/api/development/portal/user/home/address')
            ->assertNotFound()->assertJsonPath('message', 'We could not read an address at your pin. Move it on the map, or type your address in.');

        DB::connection('portal')->table('employee_home_locations')->insert([
            'employee_id' => (int) $member->getKey(), 'query' => 'map', 'source' => 'map', 'latitude' => 14.2117, 'longitude' => 121.1653, 'geocoded_at' => now(),
        ]);
        $this->atPin = [
            'house_number' => '12', 'road' => 'Rizal Street', 'village' => 'Lecheria', 'city' => 'Calamba',
            'state' => 'Laguna', 'region' => 'Calabarzon', 'ISO3166-2-lvl3' => 'PH-LAG', 'postcode' => '4027', 'country' => 'Philippines', 'country_code' => 'ph',
        ];
        $this->withToken($session)->getJson('/api/development/portal/user/home/address')->assertOk()->assertJsonPath('data', [
            'country' => 'Philippines', 'provinceCode' => '043400000', 'province' => 'Laguna', 'cityCode' => '043405000', 'city' => 'City of Calamba',
            'barangayCode' => '043405020', 'barangay' => 'Lecheria', 'street' => '12 Rizal Street',
        ]);

        // Abroad, the names stand as Nominatim gives them.
        $this->atPin = ['road' => 'Nishi-Shinjuku', 'suburb' => 'Nishi-Shinjuku', 'city' => 'Shinjuku', 'state' => 'Tokyo', 'country' => 'Japan', 'country_code' => 'jp'];
        $this->withToken($session)->getJson('/api/development/portal/user/home/address')->assertOk()->assertJsonPath('data', [
            'country' => 'Japan', 'provinceCode' => null, 'province' => 'Tokyo', 'cityCode' => null, 'city' => 'Shinjuku',
            'barangayCode' => null, 'barangay' => 'Nishi-Shinjuku', 'street' => 'Nishi-Shinjuku',
        ]);

        $this->atPin = [];
        $this->withToken($session)->getJson('/api/development/portal/user/home/address')->assertNotFound();
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

    public function test_an_empty_or_foreign_change_is_refused(): void
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
        $this->assertFalse($this->withToken($session)->getJson(self::MINE)->assertOk()->json('data.approvesOwn'));
    }

    // The reported ask (2026-10-19): a head admin's own changes reach their record as they are sent, telling no one.
    public function test_a_head_admin_s_own_changes_reach_their_record_at_once(): void
    {
        $nathaniel = $this->employee("id_no = '".self::NATHANIEL."'");
        $steven = $this->employee("id_no = '".self::STEVEN."'");
        $kept = $this->attachment($nathaniel, 'tax_identification');
        $session = $this->token($nathaniel);
        $this->assertTrue($this->withToken($session)->getJson(self::MINE)->assertOk()->json('data.approvesOwn'));

        $mine = $this->withToken($session)->post(self::MINE.'/changes', [
            'bloodType' => 'AB+',
            'files' => ['ss_id' => $this->upload('sss.pdf')],
            'removeDocumentIds' => [$kept],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertNull($mine['pending']);
        $this->assertSame('Approved', $mine['lastDecision']['status']);
        $this->assertSame('AB+', $this->record($nathaniel)['blood_type']);
        $this->assertSame(1, $this->files($nathaniel, 'ss_id'));
        $this->assertFalse(DB::connection('portal')->table('attachments')->where('id', $kept)->exists());
        $row = DB::connection('portal')->table('employee_change_requests')->where('employee_id', (int) $nathaniel->getKey())->orderByDesc('id')->first();
        $this->assertSame((int) $nathaniel->getKey(), (int) $row->decided_by);
        $this->assertNull($this->notice($steven, PortalNotificationType::PROFILE_CHANGES_TO_REVIEW, (string) $row->id));

        // One sent before that, still waiting, they may approve themselves.
        $waiting = (int) DB::connection('portal')->table('employee_change_requests')->insertGetId([
            'employee_id' => (int) $nathaniel->getKey(), 'status' => 'Pending', 'fields' => json_encode(['bloodType' => 'B+']),
            'documents' => json_encode(['add' => [], 'remove' => []]), 'submitted_at' => now(),
        ]);
        $this->withToken($session)->patchJson(self::CHANGES.'/'.$waiting, ['decision' => 'approve'])->assertOk();
        $this->assertSame('B+', $this->record($nathaniel)['blood_type']);
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
