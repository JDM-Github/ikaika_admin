<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Manage / Onboarding and the registration link (docs/power/onboarding-plan.md): an administrator
 * creates a candidate entry, the candidate signs in with the password and the mailed code, fills
 * their details and documents, submits, and the administrator promotes them into an employees row.
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
            'startDate' => '2026-11-03',
            'dateOfBirth' => '1998-04-12',
            'address' => '1 Test Street, Manila',
            'phoneNumber' => '09170000001',
            'emergencyContactPersonName' => 'Ana Reyes',
            'emergencyContactNumber' => '09170000002',
            'emergencyContactAddress' => '2 Test Street, Manila',
            'taxIdentificationNo' => '123-456-789',
            'philhealthNo' => '12-345678901-2',
            'sssNo' => '12-3456789-0',
            'hdmfNo' => '1234-5678-9012',
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

    /**
     * @param  list<array{0: string, 1: string}>  $files  name, content pairs; defaults to the six kinds
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
        ] : $files;
        $kinds = ['photo', 'tin', 'sss', 'philhealth', 'hdmf', 'bank'];

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
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('liza.reyes@example.test'));

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
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertStatus(422);

        $registration = DB::connection('portal')->table('registrations')->where('code', 'liza')->first();
        $this->assertNotNull($registration->submitted_at);
        $data = DB::connection('portal')->table('new_employee_data')->where('id', (int) $registration->employee_data_id)->first();
        $this->assertSame('Liza', $data->first_name);
        $this->assertSame('Reyes', $data->last_name);
        $this->assertSame('liza.reyes@example.test', $data->email);
        $this->assertSame('2026-11-03', $data->start_date);
        $this->assertSame('12-345678901-2', $data->philhealth_no);
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

        // A draft saved before the sanitizing is held to the same shapes when it is submitted.
        DB::connection('portal')->table('registrations')->where('code', 'clean')
            ->update(['details' => json_encode(['sssNo' => 'SS-0001'] + $this->details())]);
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter your SSS No.: 10 digits, numbers and dashes only.');
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

        // Removing one takes its row.
        $this->withToken($session)->deleteJson(self::REGISTER.'/documents/'.$second['id'])->assertNoContent();
        $this->assertSame(0, DB::connection('portal')->table('register_documents')->where('registration_id', $registrationId)->where('kind', 'tin')->count());
    }

    public function test_a_submitted_candidate_is_promoted(): void
    {
        $admin = $this->adminToken();
        $created = $this->createRegistration($admin, ['slug' => 'liza']);
        $id = (int) DB::connection('portal')->table('registrations')->where('code', 'liza')->value('id');

        // Nothing to promote before the candidate submits.
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9001', 'department' => 'MIS'])->assertStatus(422);

        $session = $this->signIn('liza', $created['password']);
        $this->withToken($session)->patchJson(self::REGISTER.'/details', $this->details())->assertOk();
        $this->uploadAll($session);
        $this->withToken($session)->postJson(self::REGISTER.'/submit')->assertOk();

        // The ID No. must fit the pattern and be free, and the email must be free.
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => 'abc', 'department' => 'MIS'])->assertStatus(422);
        $existing = (string) Employee::query()->value('id_no');
        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => $existing, 'department' => 'MIS'])->assertStatus(422);

        $employee = $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', [
            'idNo' => '991231-9001', 'department' => 'MIS', 'role' => 'User', 'jobTitle' => 'Draftsman',
        ])->assertOk()->json('data.employee');

        $this->assertSame('991231-9001', $employee['idNo']);
        $row = Employee::query()->where('id_no', '991231-9001')->first();
        $this->assertNotNull($row);
        $this->assertSame('Liza', $row->first_name);
        $this->assertSame('Reyes', $row->last_name);
        $this->assertSame('liza.reyes@example.test', $row->email);
        $this->assertSame('MIS', $row->department);
        $this->assertSame('User', $row->role);
        $this->assertSame('Active', $row->status);
        $this->assertSame('123-456-789', $row->tax_identification_no);
        $this->assertSame('BNORPHMM', $row->bank_swift_code);

        $registration = DB::connection('portal')->table('registrations')->where('id', $id)->first();
        $this->assertNotNull($registration->promoted_at);
        $this->assertSame((string) $row->getKey(), (string) $registration->promoted_employee_id);
        $data = DB::connection('portal')->table('new_employee_data')->where('id', (int) $registration->employee_data_id)->first();
        $this->assertSame('991231-9001', $data->id_no);
        $this->assertSame('Approved', $data->data_approval);

        $this->withToken($admin)->postJson(self::BASE.'/'.$id.'/promote', ['idNo' => '991231-9002', 'department' => 'MIS'])->assertStatus(422);
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
        $this->withToken($session)->getJson(self::REGISTER.'/session')->assertStatus(401);
        $this->getJson(self::REGISTER.'/liza')->assertStatus(404);

        // An expired link is dead for the password step too.
        $expired = $this->createRegistration($admin, ['slug' => 'expired-one'])['registration'];
        DB::connection('portal')->table('registrations')->where('id', $expired['id'])->update(['expires_at' => now()->subDay()]);
        $this->getJson(self::REGISTER.'/expired-one')->assertStatus(410);
    }
}
