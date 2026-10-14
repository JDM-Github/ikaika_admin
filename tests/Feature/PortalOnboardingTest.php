<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalEmployeeIdCards;
use App\Support\Portal\PortalNotificationEmails;
use App\Support\Portal\PortalRegistrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Manage / Onboarding and the registration link (docs/power/onboarding-plan.md): an administrator
 * creates a candidate entry, the candidate signs in with the password and the mailed code, fills
 * their details and documents, submits, and a head admin promotes them into an employees row.
 */
class PortalOnboardingTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/registrations';

    private const REGISTER = '/api/development/portal/register';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private const PROGRAM = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00";

    /**
     * @var list<string>
     */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function employee(string $where): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $employee->forceFill(['is_developer' => false])->save();

        return $employee;
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    // The page is an administrator's: every working call signs in an admin, in Default mode.
    private function adminToken(): string
    {
        return $this->token($this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'"));
    }

    // Promote candidates is the head admins' own (2026-10-12): Nathaniel Tanglao, then any other admin.
    private function headAdminToken(): string
    {
        return $this->token($this->employee("id_no = '220202-0002'"));
    }

    private function plainAdminToken(): string
    {
        return $this->token($this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive' AND id_no NOT IN ('220202-0002', '220302-0003')"));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'candidateName' => 'Liza Reyes',
            'email' => 'liza.reyes@example.test',
            'expiresInDays' => 30,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> registration, password
     */
    private function createRegistration(string $token, array $overrides = []): array
    {
        $created = $this->withToken($token)->postJson(self::BASE, $this->payload($overrides))->assertOk();

        return ['registration' => $created->json('data.registration'), 'password' => (string) $created->json('data.password')];
    }

    // Signs in with the password and a known code, and answers the session token.
    private function signIn(string $code, string $password): string
    {
        $this->postJson(self::REGISTER.'/'.$code.'/access', ['password' => $password])
            ->assertOk()
            ->assertJsonPath('data.otpRequired', true);
        DB::connection('portal')->table('register_otps')->where('registration_id', (int) DB::connection('portal')->table('registrations')->where('code', $code)->value('id'))
            ->update(['code_hash' => Hash::make('123456')]);

        return (string) $this->postJson(self::REGISTER.'/'.$code.'/verify', ['password' => $password, 'otp' => '123456'])
            ->assertOk()
            ->json('data.token');
    }

    /**
     * @return array<string, mixed> every required field, filled
     */
    private function details(): array
    {
        return [
            'firstName' => 'Liza',
            'middleName' => 'Cruz',
            'lastName' => 'Reyes',
            'nickname' => 'Liz',
            'startDate' => now()->addDays(21)->toDateString(),
            'dateOfBirth' => '1998-04-12',
            'bloodType' => 'O+',
            'address' => '1 Test Street, Manila',
            'phoneNumber' => '09170000001',
            'emergencyContactPersonName' => 'Ana Reyes',
            'emergencyContactNumber' => '09170000002',
            'emergencyContactAddress' => '2 Test Street, Manila',
            'taxIdentificationNo' => '123-456-789',
            'philhealthNo' => '12-345678901-2',
            'sssNo' => '12-3456789-0',
            'hdmfNo' => '1234-5678-9012',
            'nationalIdNo' => '1234-5678-9012-3456',
            'bankName' => 'BDO',
            'bankAccountNumber' => '001234567890',
            'bankSwiftCode' => 'BNORPHMM',
        ];
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'register-');
        file_put_contents($path, $content);
        $this->temporary[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    // A stroke of ink across white paper, as a signature's picture.
    private function signaturePng(): string
    {
        $picture = imagecreatetruecolor(200, 80);
        imagefill($picture, 0, 0, imagecolorallocate($picture, 255, 255, 255));
        imagefilledrectangle($picture, 40, 50, 159, 55, imagecolorallocate($picture, 0, 0, 0));
        ob_start();
        imagepng($picture);

        return (string) ob_get_clean();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files  name, content pairs; defaults to the seven kinds
     */
    private function uploadAll(string $token, array $files = []): void
    {
        $files = $files === [] ? [
            ['photo.png', $this->png()],
            ['tin.pdf', self::PDF],
            ['sss.pdf', self::PDF],
            ['philhealth.pdf', self::PDF],
            ['hdmf.pdf', self::PDF],
            ['bank.pdf', self::PDF],
            ['signature.png', $this->png()],
        ] : $files;
        $kinds = ['photo', 'tin', 'sss', 'philhealth', 'hdmf', 'bank', 'signature'];

        foreach ($files as $index => [$name, $content]) {
            $this->withToken($token)
                ->post(self::REGISTER.'/documents', ['kind' => $kinds[$index], 'file' => $this->upload($name, $content)], ['Accept' => 'application/json'])
                ->assertOk();
        }
    }

    public function test_the_page_follows_its_rule(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");

        $this->withToken($this->token($member))->getJson(self::BASE)->assertForbidden();
        $this->withToken($this->token($admin))->getJson(self::BASE)->assertOk();
    }

    // The write keys split from the read: without Create and revoke an admin still reads the list.
    public function test_the_manage_key_gates_every_write_on_its_own(): void
    {
        $admin = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($admin);
        DB::connection('portal')->table('access_presets')->where('role', 'admin')
            ->whereIn('permission_key', ['manage.onboarding.manage', 'manage.onboarding.password'])->delete();

        $this->withToken($token)->getJson(self::BASE)->assertOk();
        $this->withToken($token)->postJson(self::BASE, $this->payload())->assertForbidden();
        $this->withToken($token)->postJson(self::BASE.'/1/password')->assertForbidden();
    }

    public function test_a_registration_is_created_with_its_password_kept(): void
    {
        $token = $this->adminToken();

        $created = $this->createRegistration($token, ['slug' => 'liza']);
        $registration = $created['registration'];

        $this->assertSame('liza', $registration['code']);
        $this->assertStringEndsWith('/register/liza', $registration['link']);
        $this->assertSame('liza.reyes@example.test', $registration['email']);
        $this->assertSame('created', $registration['status']);
        $this->assertSame(12, strlen($created['password']));
        $this->assertSame($created['password'], $registration['password']);

        $row = DB::connection('portal')->table('registrations')->where('id', $registration['id'])->first();
        $this->assertNotNull($row);
        $this->assertTrue(Hash::check($created['password'], (string) $row->password_hash));
        $this->assertSame($created['password'], $row->password_value);

        // The list carries the password back, like a share's.
        $listed = collect($this->withToken($token)->getJson(self::BASE)->json('data.registrations'))->firstWhere('id', $registration['id']);
        $this->assertSame($created['password'], $listed['password']);

        // A blank slug makes a random code; a taken, malformed or reserved one is refused.
        $generated = $this->createRegistration($token, ['email' => 'other@example.test'])['registration'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', $generated['code']);
        foreach (['liza', 'Liza', 'no', 'has_underscore', 'trailing-', 'session'] as $slug) {
            $this->withToken($token)->postJson(self::BASE, $this->payload(['slug' => $slug]))->assertStatus(422);
        }
    }

    public function test_an_edit_can_change_the_slug_and_the_old_link_dies(): void
    {
        $token = $this->adminToken();
        $registration = $this->createRegistration($token)['registration'];

        $saved = $this->withToken($token)->patchJson(self::BASE.'/'.$registration['id'], $this->payload(['slug' => 'liza-reyes', 'candidateName' => 'Liza C. Reyes']))
            ->assertOk()->json('data.registration');
        $this->assertSame('liza-reyes', $saved['code']);
        $this->assertSame('Liza C. Reyes', $saved['candidateName']);
        $this->getJson(self::REGISTER.'/'.$registration['code'])->assertStatus(404);
        $this->getJson(self::REGISTER.'/liza-reyes')->assertOk()->assertJsonPath('data.name', 'Liza C. Reyes');

        // A blank slug on the next edit keeps the link it has.
        $kept = $this->withToken($token)->patchJson(self::BASE.'/'.$registration['id'], $this->payload(['slug' => '']))->assertOk()->json('data.registration');
        $this->assertSame('liza-reyes', $kept['code']);
    }

    public function test_the_sign_in_needs_the_password_and_the_mailed_code(): void
    {
        $token = $this->adminToken();
        $created = $this->createRegistration($token, ['slug' => 'liza']);

        $this->getJson(self::REGISTER.'/liza')->assertOk()->assertJsonPath('data.name', 'Liza Reyes');

        $this->postJson(self::REGISTER.'/liza/access', ['password' => 'wrong-pass'])
            ->assertStatus(422);
        $this->postJson(self::REGISTER.'/liza/access', ['password' => $created['password']])
            ->assertOk()
            ->assertJsonPath('data.otpRequired', true);
        // The portal's letter, like the sign-in code: letterhead, Company footer, from IKAIKA Portal, a greeting.
        [$footerBody] = PortalNotificationEmails::companyFooter();
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('liza.reyes@example.test')
            && $mail->subjectLine === 'Your code for Liza Reyes'
            && $mail->bodyFormat === PortalEmailMarkup::FORMAT_LETTER
            && $mail->fromName === PortalNotificationEmails::FROM_NAME
            && $mail->footerBody === $footerBody
            && str_starts_with($mail->body, 'Hi Liza Reyes,')
            && str_contains($mail->body, '::code ')
            // The candidate is already on the page asking for it, so the code email has no button back.
            && ! str_contains($mail->body, '::button'));

        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');
        DB::connection('portal')->table('register_otps')->where('registration_id', $id)->update(['code_hash' => Hash::make('123456')]);
        $this->postJson(self::REGISTER.'/liza/verify', ['password' => $created['password'], 'otp' => '654321'])->assertStatus(422);
        $this->assertSame(1, (int) DB::connection('portal')->table('register_otps')->where('registration_id', $id)->value('attempts'));

        $verify = $this->postJson(self::REGISTER.'/liza/verify', ['password' => $created['password'], 'otp' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Liza Reyes');
        // A fresh draft is an object, never an empty array: the portal's parser refuses [].
        $this->assertStringContainsString('"details":{}', $verify->getContent());
        $sessionToken = (string) $verify->json('data.token');
        $this->withToken($sessionToken)->getJson(self::REGISTER.'/session')->assertOk()->assertJsonPath('data.status', 'opened');
        $this->assertNotNull(DB::connection('portal')->table('registrations')->where('id', $id)->value('opened_at'));
    }

    public function test_a_resend_needs_the_password_and_waits_a_minute(): void
    {
        $created = $this->createRegistration($this->adminToken(), ['slug' => 'liza']);
        $this->postJson(self::REGISTER.'/liza/resend', ['password' => 'wrong-pass'])->assertStatus(422);

        $this->postJson(self::REGISTER.'/liza/access', ['password' => $created['password']])->assertOk();
        $this->postJson(self::REGISTER.'/liza/resend', ['password' => $created['password']])->assertStatus(429);

        // A minute later the resend goes out.
        DB::connection('portal')->table('register_otps')->update(['created_at' => now()->subMinutes(2)]);
        $this->postJson(self::REGISTER.'/liza/resend', ['password' => $created['password']])
            ->assertOk()
            ->assertJsonPath('data.sent', true);
    }

    public function test_details_autosave_and_submit_lands_in_new_employee_data(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'liza']);
        $session = $this->signIn('liza', $created['password']);

        // Only the fields sent change; the rest of the draft stays.
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['firstName' => 'Liza', 'lastName' => 'Reyes'])
            ->assertOk()->assertJsonPath('data.details.firstName', 'Liza');
        $saved = $this->withToken($session)->patchJson(self::REGISTER.'/details', $this->details())
            ->assertOk()->json('data.details');
        $this->assertSame('BDO', $saved['bankName']);

        // Missing documents hold the submit.
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertStatus(422);
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk()->assertJsonPath('data.submitted', true);

        // The reported ask (2026-10-12): a submitted candidate may still change what they sent, and
        // sending it again updates the same New Employee Data row rather than adding a second.
        $first = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('employee_data_id');
        $before = DB::connection('portal')->table('new_employee_data')->count();
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['nickname' => 'Lizzy'])->assertOk();
        $this->withToken($session)
            ->post(self::REGISTER.'/documents', ['kind' => 'photo', 'file' => $this->upload('new-photo.png', $this->png())], ['Accept' => 'application/json'])
            ->assertOk();
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk()->assertJsonPath('data.submitted', true);
        $this->assertSame($first, (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('employee_data_id'));
        $this->assertSame($before, DB::connection('portal')->table('new_employee_data')->count());
        $this->assertSame('Lizzy', DB::connection('portal')->table('new_employee_data')->where('id', $first)->value('nickname'));
        $this->assertSame(['new-photo.png'], DB::connection('portal')->table('register_documents')
            ->where('registration_id', (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id'))
            ->where('kind', 'photo')->pluck('name')->all());

        // Signing in again, past the resend wait, says it was already sent and that it can still change.
        $this->travel(2)->minutes();
        $this->postJson(self::REGISTER.'/liza/access', ['password' => $created['password']])->assertOk();
        DB::connection('portal')->table('register_otps')
            ->where('registration_id', (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id'))
            ->update(['code_hash' => Hash::make('123456')]);
        $again = $this->postJson(self::REGISTER.'/liza/verify', ['password' => $created['password'], 'otp' => '123456'])->assertOk();
        $this->assertNotNull($again->json('data.submittedAt'));
        $this->assertFalse($again->json('data.isLocked'));

        $registration = DB::connection('portal')->table('registrations')->where('code', 'liza')->first();
        $this->assertNotNull($registration->submitted_at);
        $data = DB::connection('portal')->table('new_employee_data')->where('id', (int) $registration->employee_data_id)->first();
        $this->assertSame('Liza', $data->first_name);
        $this->assertSame('Reyes', $data->last_name);
        $this->assertSame('liza.reyes@example.test', $data->email);
        $this->assertSame(now()->addDays(21)->toDateString(), $data->start_date);
        $this->assertSame('12-345678901-2', $data->philhealth_no);
        $this->assertSame('O+', $data->blood_type);
        $this->assertSame('1234-5678-9012-3456', $data->national_id_no);
        $this->assertSame('Pending', $data->data_approval);

        $listed = collect($this->withToken($admin)->getJson(self::BASE)->json('data.registrations'))->firstWhere('code', 'liza');
        $this->assertSame('submitted', $listed['status']);
    }

    // The reported ask (2026-10-12): the candidate's details are sanitized on save, and again at submit.
    public function test_details_are_sanitized_on_save_and_at_submit(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'clean', 'candidateName' => ' <b>Liza</b>  Reyes ']);
        $this->assertSame('Liza Reyes', $created['registration']['candidateName']);
        $session = $this->signIn('clean', $created['password']);

        $saved = $this->withToken($session)->patchJson(self::REGISTER.'/details', ['firstName' => ' <b>Liza</b>  Mae ', 'bankSwiftCode' => 'bnor phmm'])
            ->assertOk()->json('data.details');
        $this->assertSame('Liza Mae', $saved['firstName']);
        $this->assertSame('BNORPHMM', $saved['bankSwiftCode']);

        // A refused save changes nothing.
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['firstName' => 'Liza2'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'First name can only have letters, spaces, periods, apostrophes and hyphens.');
        $this->withToken($session)->getJson(self::REGISTER.'/session')->assertOk()->assertJsonPath('data.details.firstName', 'Liza Mae');

        // The national ID's image is optional (2026-10-12): accepted, and never asked for.
        $this->withToken($session)
            ->post(self::REGISTER.'/documents', ['kind' => 'national_id', 'file' => $this->upload('national.png', $this->png())], ['Accept' => 'application/json'])
            ->assertOk();

        // A draft saved before the sanitizing is held to the same shapes when it is submitted.
        DB::connection('portal')->table('registrations')->where('code', 'clean')
            ->update(['details' => json_encode(['sssNo' => 'SS-0001'] + $this->details())]);
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter your SSS No.: 10 digits, numbers and dashes only.');
    }

    // The reported ask (2026-10-13): an address is picked from the PSGC in the Philippines and typed elsewhere.
    public function test_an_address_is_picked_in_parts_and_written_as_one_line(): void
    {
        $created = $this->createRegistration($this->adminToken(), ['slug' => 'places']);
        $session = $this->signIn('places', $created['password']);

        // The lists the pickers offer: provinces, a province's cities, a city's barangays.
        $provinces = collect($this->withToken($session)->getJson(self::REGISTER.'/places/provinces')->assertOk()->json('data'));
        $this->assertCount(82, $provinces);
        $this->assertSame('Metro Manila', $provinces->firstWhere('code', '130000000')['name']);
        $cities = collect($this->withToken($session)->getJson(self::REGISTER.'/places/provinces/012800000/cities')->assertOk()->json('data'));
        $this->assertSame('Adams', $cities->firstWhere('code', '012801000')['name']);
        $this->assertContains('City of Isabela', collect($this->withToken($session)->getJson(self::REGISTER.'/places/provinces/150700000/cities')->json('data'))->pluck('name'));
        $barangays = collect($this->withToken($session)->getJson(self::REGISTER.'/places/cities/012801000/barangays')->assertOk()->json('data'));
        $this->assertSame(['Adams (Pob.)'], $barangays->pluck('name')->all());
        $this->withToken($session)->getJson(self::REGISTER.'/places/provinces/999999999/cities')->assertNotFound();
        $this->getJson(self::REGISTER.'/places/provinces', ['Authorization' => ''])->assertUnauthorized();

        // In the Philippines the codes are picked and the names are the list's; the line waits for every part.
        $home = ['addressCountry' => 'Philippines', 'addressProvinceCode' => '012800000', 'addressCityCode' => '012801000', 'addressProvince' => 'Typed over'];
        $saved = $this->withToken($session)->patchJson(self::REGISTER.'/details', $home)->assertOk()->json('data.details');
        $this->assertSame('Ilocos Norte', $saved['addressProvince']);
        $this->assertNull($saved['address']);
        $saved = $this->withToken($session)->patchJson(self::REGISTER.'/details', ['addressBarangayCode' => '012801001', 'addressStreet' => ' 12  Rizal Street '])
            ->assertOk()->json('data.details');
        $this->assertSame('12 Rizal Street, Adams (Pob.), Adams, Ilocos Norte, Philippines', $saved['address']);
        $this->assertSame('012801001', $saved['addressBarangayCode']);

        // A city outside the province, or a barangay outside the city, is refused and changes nothing.
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['addressCityCode' => '137501000'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose the city or municipality again; it is not in that province.');
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['addressBarangayCode' => '012802001'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose the barangay again; it is not in that city or municipality.');
        $this->withToken($session)->getJson(self::REGISTER.'/session')->assertOk()
            ->assertJsonPath('data.details.address', '12 Rizal Street, Adams (Pob.), Adams, Ilocos Norte, Philippines');

        // Anywhere else every part is typed, and a barangay is not needed.
        $saved = $this->withToken($session)->patchJson(self::REGISTER.'/details', [
            'emergencyContactCountry' => 'Japan', 'emergencyContactProvince' => 'Tokyo', 'emergencyContactCity' => 'Shinjuku',
            'emergencyContactStreet' => '1-2-3 Nishi Shinjuku', 'emergencyContactCityCode' => '012801000',
        ])->assertOk()->json('data.details');
        $this->assertSame('1-2-3 Nishi Shinjuku, Shinjuku, Tokyo, Japan', $saved['emergencyContactAddress']);
        $this->assertNull($saved['emergencyContactCityCode']);
        $this->assertSame('12 Rizal Street, Adams (Pob.), Adams, Ilocos Norte, Philippines', $saved['address']);

        // The whole lines are what New Employee Data receives.
        $this->withToken($session)->patchJson(self::REGISTER.'/details', array_diff_key($this->details(), ['address' => 1, 'emergencyContactAddress' => 1]))->assertOk();
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk();
        $employeeDataId = (int) DB::connection('portal')->table('registrations')->where('code', 'places')->value('employee_data_id');
        $data = DB::connection('portal')->table('new_employee_data')->where('id', $employeeDataId)->first();
        $this->assertSame('12 Rizal Street, Adams (Pob.), Adams, Ilocos Norte, Philippines', $data->address);
        $this->assertSame('1-2-3 Nishi Shinjuku, Shinjuku, Tokyo, Japan', $data->emergency_contact_address);
    }

    // The reported ask (2026-10-12): the details sheet's QR failed for want of the QR package.
    public function test_the_qr_is_an_svg_of_the_link(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'qr-link']);

        $svg = $this->withToken($admin)->getJson(self::BASE.'/'.$created['registration']['id'].'/qr')->assertOk()->json('data.svg');
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
    }

    public function test_documents_are_checked_and_kept_privately(): void
    {
        $created = $this->createRegistration($this->adminToken(), ['slug' => 'liza']);
        $registrationId = (int) $created['registration']['id'];
        $session = $this->signIn('liza', $created['password']);

        // A program renamed .pdf is refused by content, and an unknown kind is refused.
        $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'tin', 'file' => $this->upload('Setup.pdf', self::PROGRAM)], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'passport', 'file' => $this->upload('id.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertStatus(422);
        // The photo is an image kind.
        $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'photo', 'file' => $this->upload('photo.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertStatus(422);
        // The reported ask (2026-10-14): the signature is a picture too.
        $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'signature', 'file' => $this->upload('signature.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', 'The signature is a JPG or JPEG or PNG file.');
        $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'signature', 'file' => $this->upload('signature.png', $this->png())], ['Accept' => 'application/json'])
            ->assertOk();

        $first = $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'tin', 'file' => $this->upload('tin.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.document');
        $this->assertSame('tin', $first['kind']);
        $row = DB::connection('portal')->table('register_documents')->where('id', (int) $first['id'])->first();
        $this->assertStringStartsWith('registrations/', (string) $row->path);

        // One file per kind: the newer replaces the older, and its bytes go.
        $second = $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'tin', 'file' => $this->upload('tin-2.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.document');
        $this->assertSame(1, DB::connection('portal')->table('register_documents')->where('registration_id', $registrationId)->where('kind', 'tin')->count());
        $this->assertNull(DB::connection('portal')->table('register_documents')->where('id', (int) $first['id'])->first());

        // The reported ask (2026-10-12): the candidate reads their own file back to preview it, and nobody else's.
        $own = $this->withToken($session)->get(self::REGISTER.'/documents/'.$second['id']);
        $own->assertOk();
        $this->assertSame(self::PDF, $own->streamedContent());
        $other = $this->createRegistration($this->adminToken(), ['slug' => 'someone-else', 'email' => 'someone.else@example.test']);
        $otherSession = $this->signIn('someone-else', $other['password']);
        $this->withToken($otherSession)->getJson(self::REGISTER.'/documents/'.$second['id'])->assertNotFound();
        $this->getJson(self::REGISTER.'/documents/'.$second['id'], ['Authorization' => ''])->assertUnauthorized();

        // Removing one takes its row.
        $this->withToken($session)->deleteJson(self::REGISTER.'/documents/'.$second['id'])->assertNoContent();
        $this->assertSame(0, DB::connection('portal')->table('register_documents')->where('registration_id', $registrationId)->where('kind', 'tin')->count());
    }

    public function test_a_submitted_candidate_is_promoted(): void
    {
        $plain = $this->plainAdminToken();
        $created = $this->createRegistration($plain, ['slug' => 'liza']);
        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');
        $admin = $this->headAdminToken();

        // The reported ask (2026-10-12): only the head admins promote; Create and revoke alone does not.
        $this->withToken($plain)->getJson(self::BASE.'/'.$id.'/promotion')->assertForbidden();
        $this->withToken($plain)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9001', 'email' => 'a@ikaikabim.test', 'department' => 'MIS'])->assertForbidden();

        // Nothing to promote before the candidate submits.
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9001', 'department' => 'MIS'])->assertStatus(422);
        $this->withToken($admin)->getJson(self::BASE.'/'.$id.'/promotion')
            ->assertStatus(422)->assertJsonPath('message', 'The candidate has not submitted their requirements yet.');

        $session = $this->signIn('liza', $created['password']);
        $this->withToken($session)->patchJson(self::REGISTER.'/details', $this->details())->assertOk();
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk();

        // Promote opens its sheet on the submitted name and the next free ID No.
        $promotion = $this->withToken($admin)->getJson(self::BASE.'/'.$id.'/promotion')->assertOk()->json('data');
        $this->assertSame(['Liza', 'Cruz', 'Reyes'], [$promotion['firstName'], $promotion['middleName'], $promotion['lastName']]);
        $this->assertMatchesRegularExpression('/^\d{6}-\d{4}$/', $promotion['nextIdNo']);
        $this->assertContains('MIS', $promotion['departments']);

        // The ID No. must fit the pattern and be free, and the work email given and free.
        $work = 'liza.reyes@ikaikabim.test';
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => 'abc', 'email' => $work, 'department' => 'MIS'])->assertStatus(422);
        $existing = (string) Employee::query()->value('id_no');
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => $existing, 'email' => $work, 'department' => 'MIS'])->assertStatus(422);
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9001', 'department' => 'MIS'])->assertStatus(422);
        $taken = (string) Employee::query()->whereNotNull('email')->where('email', '!=', '')->value('email');
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9001', 'email' => $taken, 'department' => 'MIS'])
            ->assertStatus(422)->assertJsonPath('message', 'Someone already has that email address.');

        $employee = $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9001', 'email' => $work, 'department' => 'MIS', 'role' => 'User', 'jobTitle' => 'Draftsman',
        ])->assertOk()->json('data.employee');

        $this->assertSame('991231-9001', $employee['idNo']);
        $row = Employee::query()->where('id_no', '991231-9001')->first();
        $this->assertNotNull($row);
        // Ledgered as an add, so the Airtable sync creates them there (2026-10-14, user).
        $this->assertTrue(DB::connection('core')->table('actions')->where('resource', 'employees')->where('action_type', 'add')
            ->where('record_id', (string) $row->getKey())->exists());
        // Their New Employee Data row too: added when they submitted, edited when promotion named their ID No.
        $dataId = (string) DB::connection('portal')->table('registrations')->where('id', $id)->value('employee_data_id');
        $this->assertSame(['add', 'edit'], DB::connection('core')->table('actions')->where('resource', 'onboarding.employee_data')
            ->where('record_id', $dataId)->orderBy('id')->pluck('action_type')->all());
        $this->assertSame('Liza', $row->first_name);
        $this->assertSame('Reyes', $row->last_name);
        // The work email signs them in; the address the link was bound to is kept as their personal one.
        $this->assertSame($work, $row->email);
        $this->assertSame('liza.reyes@example.test', $row->personal_email);
        $this->assertSame('MIS', $row->department);
        $this->assertSame('User', $row->role);
        // The reported ask (2026-10-12): a head admin promoted them, so the account is active at once.
        $this->assertSame('Active', $row->status);
        $this->postJson('/api/development/portal/auth/login', ['id_no' => '991231-9001'])->assertOk();
        $this->assertSame('123-456-789', $row->tax_identification_no);
        $this->assertSame('BNORPHMM', $row->bank_swift_code);
        $this->assertSame('O+', $row->blood_type);

        $registration = DB::connection('portal')->table('registrations')->where('id', $id)->first();
        $this->assertNotNull($registration->promoted_at);
        $this->assertSame((string) $row->getKey(), (string) $registration->promoted_employee_id);
        $data = DB::connection('portal')->table('new_employee_data')->where('id', (int) $registration->employee_data_id)->first();
        $this->assertSame('991231-9001', $data->id_no);
        $this->assertSame('Approved', $data->data_approval);

        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9002', 'email' => 'another@ikaikabim.test', 'department' => 'MIS'])->assertStatus(422);
        $this->withToken($admin)->getJson(self::BASE.'/'.$id.'/promotion')
            ->assertStatus(422)->assertJsonPath('message', 'This candidate is already an employee.');

        // Promoted, the registration is the employee record's source and no longer changes.
        $locked = 'HR has already approved this registration, so it can no longer be changed.';
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['nickname' => 'Late'])
            ->assertStatus(422)->assertJsonPath('message', $locked);
        $this->withToken($session)
            ->post(self::REGISTER.'/documents', ['kind' => 'photo', 'file' => $this->upload('late.png', $this->png())], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', $locked);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertStatus(422)->assertJsonPath('message', $locked);

        // The reported ask (2026-10-12): nor does the admin change, revoke, send or re-password it.
        $settled = 'This candidate is already an employee, so the registration can no longer be changed.';
        $this->withToken($plain)->patchJson(self::BASE.'/'.$id, $this->payload())->assertStatus(422)->assertJsonPath('message', $settled);
        $this->withToken($plain)->deleteJson(self::BASE.'/'.$id)->assertStatus(422)->assertJsonPath('message', $settled);
        $this->withToken($plain)->postJson(self::BASE.'/'.$id.'/sent')->assertStatus(422)->assertJsonPath('message', $settled);
        $this->withToken($plain)->postJson(self::BASE.'/'.$id.'/password')->assertStatus(422)->assertJsonPath('message', $settled);
        $this->assertNull(DB::connection('portal')->table('registrations')->where('id', $id)->value('revoked_at'));
    }

    private function fakeCloudinary(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'demo-cloud',
            'services.cloudinary.api_key' => 'key123',
            'services.cloudinary.api_secret' => 'secret456',
        ]);
        $uploads = 0;
        Http::fake([
            'https://api.cloudinary.com/v1_1/demo-cloud/auto/upload' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['secure_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/file'.$uploads]);
            },
            '*' => Http::response([], 200),
        ]);
    }

    // A submitted registration, ready to promote, and its id.
    private function submittedRegistration(string $plain): int
    {
        $created = $this->createRegistration($plain, ['slug' => 'liza']);
        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');
        $session = $this->signIn('liza', $created['password']);
        $this->withToken($session)->patchJson(self::REGISTER.'/details', $this->details())->assertOk();
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk();

        return $id;
    }

    /**
     * @return array<string, int> field name => files there
     */
    private function cloudinaryFields(int $employeeId): array
    {
        return DB::connection('portal')->table('attachments')->where('table_name', 'employees')->where('record_id', $employeeId)
            ->selectRaw('field_name, COUNT(*) AS files')->groupBy('field_name')->pluck('files', 'field_name')->map(fn ($n): int => (int) $n)->all();
    }

    // The reported ask (2026-10-12): a promoted employee's documents are on Cloudinary like everyone's, the
    // photo their profile photo, and whoever sees their private details reads those and edits them.
    public function test_promotion_puts_the_candidates_files_on_cloudinary_as_their_documents(): void
    {
        $this->fakeCloudinary();
        $plain = $this->plainAdminToken();
        $id = $this->submittedRegistration($plain);
        $head = $this->headAdminToken();
        $employeeId = (int) $this->withToken($head)->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9011', 'email' => 'liza.reyes@ikaikabim.test', 'department' => 'MIS',
        ])->assertOk()->json('data.employee.id');

        // Each file under the field Airtable gives it, as every employee's documents are kept.
        $this->assertEqualsCanonicalizing(
            ['photo' => 1, 'signature' => 1, 'tax_identification' => 1, 'ss_id' => 1, 'philhealth_id' => 1, 'hdmf_id' => 1, 'bank_certificate' => 1],
            $this->cloudinaryFields($employeeId),
        );

        $users = '/api/development/portal/manage/users/'.$employeeId;
        $profile = $this->withToken($head)->getJson($users)->assertOk()->json('data');
        $this->assertStringStartsWith('https://res.cloudinary.com/demo-cloud/', (string) $profile['photoUrl']);
        $this->assertTrue($profile['canEditInformation']);
        $documents = collect($profile['documents']);
        $this->assertCount(7, $documents);
        $photo = $documents->firstWhere('field', 'photo');
        $this->assertStringStartsWith('https://res.cloudinary.com/demo-cloud/', $photo['url']);
        $this->assertStringContainsString('/upload/c_fill,h_96,w_96', (string) $photo['thumbUrl']);
        $this->assertSame('image/png', $photo['mimeType']);
        $this->assertNull($photo['pagesUrl']);
        // A PDF previews as pictures of its pages, its first page as its thumbnail (2026-10-14).
        $tin = $documents->firstWhere('field', 'tax_identification');
        $this->assertStringContainsString('/upload/pg_1,c_fill,h_96,w_96,f_jpg,q_auto/', (string) $tin['thumbUrl']);
        $this->assertStringContainsString('/upload/pg_{page},f_jpg,w_1400,q_auto/', (string) $tin['pagesUrl']);

        // Copying again finds theirs all there already, so nothing doubles.
        $this->artisan('portal:copy-registration-documents')->expectsOutputToContain('already on Cloudinary')->assertExitCode(0);
        $this->assertSame([1], array_values(array_unique($this->cloudinaryFields($employeeId))));

        // Someone without Show employee information sees no documents and changes nothing.
        $plainProfile = $this->withToken($plain)->getJson($users)->assertOk()->json('data');
        $this->assertSame([], $plainProfile['documents']);
        $this->assertFalse($plainProfile['canEditInformation']);
        $this->withToken($plain)->patchJson($users.'/information', ['address' => '9 New Street, Manila'])->assertForbidden();

        // Only what is sent changes, cleaned the way a registration cleans it.
        $saved = $this->withToken($head)->patchJson($users.'/information', [
            'address' => '  9 <b>New</b> Street,   Manila ',
            'personalEmail' => ' Liza.Personal@Example.test ',
            'emergencyContactName' => 'Ana   Reyes-Cruz',
            'bankSwiftCode' => 'bnor phmm',
        ])->assertOk()->json('data.information');
        $this->assertSame('9 New Street, Manila', $saved['address']);
        $this->assertSame('liza.personal@example.test', $saved['personalEmail']);
        $this->assertSame('Ana Reyes-Cruz', $saved['emergencyContactName']);
        $this->assertSame('BNORPHMM', $saved['bankSwiftCode']);
        $this->assertSame('123-456-789', $saved['taxIdentificationNo']);
        $this->assertTrue(DB::connection('portal')->table('logs')->where('record_id', (string) $employeeId)
            ->where('message', 'like', '%changed the employee information of%')->exists());
        // Ledgered by column name only (2026-10-14), and only what changed: the swift code already read that.
        $ledgered = DB::connection('core')->table('actions')->where('resource', 'employees')->where('record_id', (string) $employeeId)
            ->where('action_type', 'edit')->orderByDesc('id')->value('parameters');
        $this->assertSame(['address', 'personal_email', 'emergency_contact_person_name'], json_decode((string) $ledgered, true)['parameters']['columns']);
        $this->assertStringNotContainsString('Manila', (string) $ledgered);

        $this->withToken($head)->patchJson($users.'/information', ['sssNo' => '1111111111'])
            ->assertStatus(422)->assertJsonPath('message', 'SSS No. cannot be one digit repeated. Check it.');
        $this->withToken($head)->patchJson($users.'/information', ['personalEmail' => 'not-an-email'])->assertStatus(422);
        $this->withToken($head)->patchJson($users.'/information', [])->assertStatus(422)->assertJsonPath('message', 'Change at least one detail.');
        $this->withToken($head)->patchJson($users.'/information', ['bloodType' => null])->assertOk()->assertJsonPath('data.information.bloodType', null);
    }

    // The reported ask (2026-10-13): whoever edits employee information puts files in their slots, a
    // signature among them, replaces one, adds bank certificates up to five, and takes any off.
    public function test_whoever_edits_employee_information_changes_their_documents(): void
    {
        $this->fakeCloudinary();
        $plain = $this->plainAdminToken();
        $id = $this->submittedRegistration($plain);
        $head = $this->headAdminToken();
        $employeeId = (int) $this->withToken($head)->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9013', 'email' => 'liza.reyes@ikaikabim.test', 'department' => 'MIS',
        ])->assertOk()->json('data.employee.id');
        $documents = '/api/development/portal/manage/users/'.$employeeId.'/documents';
        $put = fn (string $field, string $name, string $content) => $this->withToken($head)
            ->post($documents, ['field' => $field, 'file' => $this->upload($name, $content)], ['Accept' => 'application/json']);

        // A signature is a slot of its own, and a new photo replaces the old one.
        $profile = $put('signature', 'signature.png', $this->png())->assertCreated()->json('data');
        $this->assertSame('signature.png', collect($profile['documents'])->firstWhere('field', 'signature')['name']);
        $oldPhoto = $profile['photoUrl'];
        $profile = $put('photo', 'new-photo.png', $this->png())->assertCreated()->json('data');
        $this->assertNotSame($oldPhoto, $profile['photoUrl']);
        $this->assertSame(['new-photo.png'], collect($profile['documents'])->where('field', 'photo')->pluck('name')->values()->all());
        $put('tax_identification', 'tin.pdf', self::PDF)->assertCreated();
        $this->assertSame(1, $this->cloudinaryFields($employeeId)['tax_identification']);

        // Bank certificates gather, up to five.
        foreach (range(2, 5) as $count) {
            $put('bank_certificate', "bank-{$count}.pdf", self::PDF)->assertCreated();
        }
        $put('bank_certificate', 'bank-6.pdf', self::PDF)->assertStatus(422)
            ->assertJsonPath('message', 'Keep up to 5 bank certificates. Remove one first.');
        $this->assertSame(5, $this->cloudinaryFields($employeeId)['bank_certificate']);

        // A picture slot takes a picture, a slot has to be named, and a file has to come.
        $put('signature', 'signature.pdf', self::PDF)->assertStatus(422)
            ->assertJsonPath('message', 'The signature has to be a JPG, PNG, WebP or GIF picture.');
        $put('tax_identification', 'Setup.pdf', self::PROGRAM)->assertStatus(422)
            ->assertJsonPath('message', 'The TIN ID has to be a picture or a PDF.');
        $put('passport', 'passport.png', $this->png())->assertStatus(422)->assertJsonPath('message', 'Choose which document this is.');
        $this->withToken($head)->postJson($documents, ['field' => 'photo'])->assertStatus(422)->assertJsonPath('message', 'Choose a file to upload.');

        // Any of their files comes off; one that is not theirs is not found.
        $signatureId = (int) DB::connection('portal')->table('attachments')
            ->where(['table_name' => 'employees', 'record_id' => $employeeId, 'field_name' => 'signature'])->value('id');
        $left = $this->withToken($head)->deleteJson($documents.'/'.$signatureId)->assertOk()->json('data.documents');
        $this->assertNull(collect($left)->firstWhere('field', 'signature'));
        $this->withToken($head)->deleteJson($documents.'/'.$signatureId)->assertNotFound();
        $this->assertTrue(DB::connection('portal')->table('logs')->where('record_id', (string) $employeeId)
            ->where('message', 'like', '%changed the documents of%')->exists());

        // Without Edit employee information, nothing changes.
        $photoId = (int) DB::connection('portal')->table('attachments')
            ->where(['table_name' => 'employees', 'record_id' => $employeeId, 'field_name' => 'photo'])->value('id');
        $this->withToken($plain)->post($documents, ['field' => 'signature', 'file' => $this->upload('s.png', $this->png())], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->withToken($plain)->deleteJson($documents.'/'.$photoId)->assertForbidden();
    }

    // The reported ask (2026-10-14): an ID card's front and back, its QR code in the company's format.
    public function test_an_id_card_carries_what_its_front_and_back_print(): void
    {
        config([
            'services.cloudinary.cloud_name' => 'demo-cloud',
            'services.cloudinary.api_key' => 'key123',
            'services.cloudinary.api_secret' => 'secret456',
        ]);
        $uploads = 0;
        Http::fake([
            'https://api.cloudinary.com/v1_1/demo-cloud/auto/upload' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['secure_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/file'.$uploads]);
            },
            'https://res.cloudinary.com/*e_trim*' => Http::response($this->signaturePng(), 200, ['Content-Type' => 'image/png']),
            'https://res.cloudinary.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
            '*' => Http::response([], 200),
        ]);
        $plain = $this->plainAdminToken();
        $id = $this->submittedRegistration($plain);
        $head = $this->headAdminToken();
        $employeeId = (int) $this->withToken($head)->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9014', 'email' => 'liza.reyes@ikaikabim.test', 'department' => 'MIS',
        ])->assertOk()->json('data.employee.id');
        $users = '/api/development/portal/manage/users/'.$employeeId;

        $card = $this->withToken($head)->getJson($users.'/id-card')->assertOk()->json('data');
        $this->assertSame('Liza C. Reyes', $card['name']);
        $this->assertSame('991231-9014', $card['idNo']);
        $this->assertSame('O+', $card['bloodType']);
        $this->assertSame('Ana Reyes', $card['emergencyContactName']);
        $this->assertStringStartsWith("Name : Liza C. Reyes\nPosition : ", $card['qr']['text']);
        $this->assertStringEndsWith("ID-No. : 991231-9014\nCompany : IKAIKA OPC\nEmergency Contact : Ana Reyes - 09170000002", $card['qr']['text']);
        // A square of modules, each row as wide as there are rows.
        $this->assertGreaterThanOrEqual(21, count($card['qr']['rows']));
        $this->assertSame([count($card['qr']['rows'])], array_values(array_unique(array_map('strlen', $card['qr']['rows']))));
        $this->assertMatchesRegularExpression('/^[01]+$/', implode('', $card['qr']['rows']));
        // The photo, the signature and the mark come in as pictures, the photo cut for the card.
        foreach (['photo', 'signature', 'logo'] as $image) {
            $this->assertStringStartsWith('data:image/png;base64,', (string) $card[$image]);
        }
        // The signature as its ink alone, cut to its stroke.
        $this->assertFalse($card['signatureIsUnclear']);
        $this->assertSame([124, 10], array_slice((array) getimagesizefromstring((string) base64_decode(substr((string) $card['signature'], 22))), 0, 2));
        // Its shape, so the card gives it about the same room as any other.
        $this->assertSame(12.4, $card['signatureAspect']);
        // The photo without its background, so the plain one is never asked for.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/upload/e_background_removal/c_fill,g_face,w_480,h_642/f_png/'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/upload/c_fill,g_face,w_480,h_642,f_jpg,q_90/'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/upload/e_trim/c_limit,w_1000,h_1000,f_png/'));
        $this->assertTrue(DB::connection('portal')->table('logs')->where('record_id', (string) $employeeId)
            ->where('message', 'like', '%made the ID card of%')->exists());

        // Its own power, not Show employee information: a plain admin holds Generate ID by preset and
        // makes the card of someone in their reach; a member holds nothing that opens it.
        $this->withToken($plain)->getJson($users.'/id-card')->assertOk()->assertJsonPath('data.idNo', '991231-9014');
        $member = $this->token($this->employee("LOWER(COALESCE(role, '')) = 'user' AND LOWER(COALESCE(role_level, '')) != 'executive'"));
        $this->withToken($member)->getJson($users.'/id-card')->assertForbidden();
        $this->assertSame('Jhon Noriel D. Malabanan', PortalEmployeeIdCards::cardName(' Jhon Noriel ', 'dimaculangan', 'Malabanan'));
        $this->assertSame('Jhon Malabanan', PortalEmployeeIdCards::cardName('Jhon', '  ', 'Malabanan'));
    }

    // The reported ask (2026-10-14): a photo that could not be fetched left the card blank without a word.
    public function test_a_card_picture_that_cannot_be_fetched_is_left_blank_and_logged(): void
    {
        Log::spy();
        Http::fake(['https://res.cloudinary.com/*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem')]);
        $employee = $this->employee("LOWER(COALESCE(role, '')) = 'admin'");
        DB::connection('portal')->table('attachments')->insert([
            'table_name' => 'employees', 'record_id' => (int) $employee->getKey(), 'field_name' => 'photo',
            'file_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/face.jpg',
            'file_name' => 'face.jpg', 'file_size_bytes' => 900, 'mime_type' => 'image/jpeg',
        ]);

        $card = app(PortalEmployeeIdCards::class)->card($employee);

        $this->assertNull($card['photo']);
        // Once without its background and once as taken.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'portal.id_card.picture_unavailable'
            && $context['field'] === 'photo' && str_contains($context['reason'], 'SSL certificate problem'))->twice();
    }

    // The reported ask (2026-10-14): a random picture uploaded as a signature printed on the card, shrunk onto the line.
    public function test_a_signature_on_file_that_is_no_signature_is_left_blank_and_said_to_be_unclear(): void
    {
        // A page of many separate marks, as a page of text or a QR code is.
        $page = imagecreatetruecolor(400, 400);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        for ($row = 0; $row < 10; $row++) {
            for ($column = 0; $column < 10; $column++) {
                imagefilledrectangle($page, 20 + $column * 36, 20 + $row * 36, 28 + $column * 36, 28 + $row * 36, imagecolorallocate($page, 0, 0, 0));
            }
        }
        ob_start();
        imagepng($page);
        Http::fake(['https://res.cloudinary.com/*' => Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/png'])]);
        $employee = $this->employee("LOWER(COALESCE(role, '')) = 'admin'");
        DB::connection('portal')->table('attachments')->insert([
            'table_name' => 'employees', 'record_id' => (int) $employee->getKey(), 'field_name' => 'signature',
            'file_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/page.png',
            'file_name' => 'page.png', 'file_size_bytes' => 900, 'mime_type' => 'image/png',
        ]);

        $card = app(PortalEmployeeIdCards::class)->card($employee);

        $this->assertNull($card['signature']);
        $this->assertTrue($card['signatureIsUnclear']);
        $this->assertNull($card['signatureAspect']);
    }

    // The reported ask (2026-10-14): a photo's background is taken out; while Cloudinary is still at it, the photo comes as taken.
    public function test_a_photo_whose_background_is_still_being_removed_comes_as_taken(): void
    {
        Sleep::fake();
        Log::spy();
        Http::fake([
            'https://res.cloudinary.com/*e_background_removal*' => Http::response('', 423),
            'https://res.cloudinary.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $employee = $this->employee("LOWER(COALESCE(role, '')) = 'admin'");
        DB::connection('portal')->table('attachments')->insert([
            'table_name' => 'employees', 'record_id' => (int) $employee->getKey(), 'field_name' => 'photo',
            'file_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/ikaika-portal/employees/face.jpg',
            'file_name' => 'face.jpg', 'file_size_bytes' => 900, 'mime_type' => 'image/jpeg',
        ]);

        $card = app(PortalEmployeeIdCards::class)->card($employee);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $card['photo']);
        // Asked five times, two seconds apart, before the photo as taken is used.
        $this->assertCount(5, Http::recorded(fn ($request): bool => str_contains($request->url(), '/e_background_removal/')));
        Sleep::assertSleptTimes(4);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/upload/c_fill,g_face,w_480,h_642,f_jpg,q_90/'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'portal.id_card.picture_unavailable'
            && str_starts_with($context['transform'], 'e_background_removal/') && str_starts_with($context['reason'], 'HTTP 423'))->once();
    }

    // A file that cannot be stored never stops a promotion; the copy command finishes the job later.
    public function test_files_that_cannot_be_stored_never_stop_a_promotion_and_are_copied_later(): void
    {
        config(['services.cloudinary.cloud_name' => null]);
        $id = $this->submittedRegistration($this->plainAdminToken());
        $employeeId = (int) $this->withToken($this->headAdminToken())->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9012', 'email' => 'liza.reyes@ikaikabim.test', 'department' => 'MIS',
        ])->assertOk()->json('data.employee.id');

        $this->assertSame([], $this->cloudinaryFields($employeeId));
        $this->assertNotNull(Employee::query()->find($employeeId));
        $this->artisan('portal:copy-registration-documents')->expectsOutputToContain('failed.')->assertExitCode(1);
        $this->assertSame([], $this->cloudinaryFields($employeeId));

        $this->fakeCloudinary();
        $this->artisan('portal:copy-registration-documents')->expectsOutputToContain('copied')->assertExitCode(0);
        $this->assertCount(7, $this->cloudinaryFields($employeeId));
    }

    public function test_a_document_downloads_for_an_admin_and_never_for_a_member(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'liza']);
        $session = $this->signIn('liza', $created['password']);
        $document = $this->withToken($session)->post(self::REGISTER.'/documents', ['kind' => 'tin', 'file' => $this->upload('tin.pdf', self::PDF)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.document');
        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');

        $this->withToken($admin)->get(self::BASE.'/'.$id.'/documents/'.(int) $document['id'])
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $this->withToken($this->token($member))->get(self::BASE.'/'.$id.'/documents/'.(int) $document['id'])->assertForbidden();
    }

    public function test_revoke_and_expiry_end_a_live_session(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'liza']);
        $session = $this->signIn('liza', $created['password']);
        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');

        $this->withToken($admin)->deleteJson(self::BASE.'/'.$id)->assertOk();
        // Revoke is how HR turns a candidate down (2026-10-12): changes stop, and the candidate is told why.
        $this->withToken($session)->getJson(self::REGISTER.'/session')
            ->assertStatus(401)->assertJsonPath('message', PortalRegistrations::CLOSED);
        $this->withToken($session)->patchJson(self::REGISTER.'/details', ['nickname' => 'Late'])->assertStatus(401);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertStatus(401);
        $this->getJson(self::REGISTER.'/liza')->assertStatus(404)->assertJsonPath('message', PortalRegistrations::CLOSED);
        $this->postJson(self::REGISTER.'/liza/access', ['password' => $created['password']])
            ->assertStatus(404)->assertJsonPath('message', PortalRegistrations::CLOSED);
        // A link that never was still reads as not active.
        $this->getJson(self::REGISTER.'/never-made')->assertStatus(404)->assertJsonPath('message', 'This link is not active.');

        // An expired link is dead for the password step too.
        $expired = $this->createRegistration($admin, ['slug' => 'expired-one'])['registration'];
        DB::connection('portal')->table('registrations')->where('id', $expired['id'])->update(['expires_at' => now()->subDay()]);
        $this->getJson(self::REGISTER.'/expired-one')->assertStatus(410);
    }
}
