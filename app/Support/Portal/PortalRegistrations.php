<?php

namespace App\Support\Portal;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Manage / Onboarding (docs/power/onboarding-plan.md): the candidate entries behind the registration
 * links. An entry carries the bound email the OTP is mailed to, a slug or generated code, a
 * kept-readable generated password and an expiry; the candidate signs in with the password and the
 * OTP, fills their details and documents over the register/* session routes, and the administrator
 * promotes a submitted entry into an employees row. The submitted data lands in new_employee_data.
 */
final class PortalRegistrations
{
    private const DEFAULT_EXPIRY_DAYS = 30;

    private const MAX_EXPIRY_DAYS = 365;

    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    private const OTP_RESEND_SECONDS = 60;

    // A session may outlive the portal's own JWT TTL; the link's expiry bounds it, and a
    // never-expiring link still ends here.
    private const MAX_SESSION_TTL_SECONDS = 2592000;

    // The session middleware's own cap, so uploads are not throttled by the sign-in limiter.
    public const SESSION_REQUESTS_PER_MINUTE = 120;

    private const ID_NO = '/^\d{6}-\d{4}$/';

    /**
     * The draft fields the candidate fills, in the order the steps ask for them. The email is bound
     * to the link and is never sent from the client.
     *
     * @var array<string, string>
     */
    private const DETAIL_RULES = [
        'firstName' => 'max:100',
        'middleName' => 'max:100',
        'lastName' => 'max:100',
        'nickname' => 'max:100',
        'startDate' => 'date',
        'dateOfBirth' => 'date',
        'address' => 'max:500',
        'phoneNumber' => 'max:50',
        'emergencyContactPersonName' => 'max:255',
        'emergencyContactNumber' => 'max:50',
        'emergencyContactAddress' => 'max:500',
        'taxIdentificationNo' => 'max:100',
        'philhealthNo' => 'max:100',
        'sssNo' => 'max:100',
        'hdmfNo' => 'max:100',
        'bankName' => 'max:150',
        'bankAccountNumber' => 'max:100',
        'bankSwiftCode' => 'max:50',
    ];

    /** What a submit must have, the old form's required set. */
    private const REQUIRED_DETAILS = [
        'firstName', 'lastName', 'startDate', 'dateOfBirth', 'address', 'phoneNumber',
        'emergencyContactPersonName', 'emergencyContactNumber', 'emergencyContactAddress',
        'taxIdentificationNo', 'philhealthNo', 'sssNo', 'hdmfNo',
        'bankName', 'bankAccountNumber', 'bankSwiftCode',
    ];

    public function __construct(
        private readonly PortalAudit $audit,
        private readonly PortalJwt $jwt,
        private readonly PortalRegistrationDocuments $documents,
    ) {}

    /**
     * The panel: every entry, newest first.
     *
     * @return array{registrations: list<array<string, mixed>>}
     */
    public function index(): array
    {
        $rows = $this->connection()->table('registrations')->orderByDesc('id')->get();

        return ['registrations' => $rows->map(fn (object $row): array => $this->present($row))->values()->all()];
    }

    /**
     * @return array{registration: array<string, mixed>, password: string}
     */
    public function create(Employee $actor, Request $request): array
    {
        $data = $this->validated($request);
        $password = PortalCredentials::password();
        $code = $this->codeFor($data['slug']);

        $id = (int) $this->connection()->table('registrations')->insertGetId([
            'candidate_name' => $data['candidateName'],
            'email' => $data['email'],
            'code' => $code,
            'password_hash' => Hash::make($password),
            'password_value' => $password,
            'expires_at' => $data['expiresAt'],
            'created_by' => (int) $actor->getKey(),
        ]);
        $this->audit->record($actor, PortalLogAction::INSERT, 'manage.onboarding', '{UserName|You} created the registration link for '.$data['candidateName'], (string) $id, $request);

        return ['registration' => $this->present($this->row($id)), 'password' => $password];
    }

    /**
     * @return array{registration: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $data = $this->validated($request);

        $update = [
            'candidate_name' => $data['candidateName'],
            'email' => $data['email'],
            'expires_at' => $data['expiresAt'],
        ];
        // A slug left blank keeps the link the entry already has; a typed one replaces it.
        if ($data['slug'] !== null) {
            $update['code'] = $this->codeFor($data['slug'], $id);
        }
        $this->connection()->table('registrations')->where('id', $id)->update($update);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.onboarding', '{UserName|You} changed the registration link for '.$data['candidateName'], (string) $id, $request);

        return ['registration' => $this->present($this->row($id))];
    }

    /**
     * @return array{registration: array<string, mixed>}
     */
    public function revoke(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $this->connection()->table('registrations')->where('id', $id)->update(['revoked_at' => now()]);
        $this->audit->record($actor, PortalLogAction::DELETE, 'manage.onboarding', '{UserName|You} revoked the registration link for '.trim((string) $row->candidate_name), (string) $id, $request);

        return ['registration' => $this->present($this->row($id))];
    }

    /**
     * @return array{password: string}
     */
    public function regenerate(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $password = PortalCredentials::password();
        $this->connection()->table('registrations')->where('id', $id)->update([
            'password_hash' => Hash::make($password),
            'password_value' => $password,
        ]);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.onboarding', '{UserName|You} changed the password of the registration link for '.trim((string) $row->candidate_name), (string) $id, $request);

        return ['password' => $password];
    }

    /**
     * The Send button opens the composer on this entry's draft; the stamp says it was handed over.
     *
     * @return array{registration: array<string, mixed>}
     */
    public function markSent(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $this->connection()->table('registrations')->where('id', $id)->update(['sent_at' => now()]);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.onboarding', '{UserName|You} sent the registration link for '.trim((string) $row->candidate_name), (string) $id, $request);

        return ['registration' => $this->present($this->row($id))];
    }

    public function qr(int $id): string
    {
        $row = $this->row($id);
        $renderer = new ImageRenderer(new RendererStyle(360, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->link((string) $row->code));
    }

    /**
     * One entry with what the candidate submitted: the draft, the documents, the promoted employee.
     *
     * @return array{registration: array<string, mixed>, details: array<string, mixed>, documents: list<array<string, mixed>>}
     */
    public function detail(int $id): array
    {
        $row = $this->row($id);

        return [
            'registration' => $this->present($row),
            'details' => $this->detailsOf($row),
            'documents' => $this->documents->listFor($id),
        ];
    }

    /**
     * One document of one entry, as a download under its own name.
     *
     * @return array{name: string, mime: string, path: string}
     */
    public function document(int $id, int $documentId): array
    {
        $this->row($id);

        return $this->documents->rowFor($id, $documentId);
    }

    /**
     * A submitted entry becomes an employees row, the same rules Add user follows: the ID No pattern,
     * a unique ID and email, Active, and the private columns from the registration.
     *
     * @return array{employee: array{id: string, idNo: string, name: string}}
     */
    public function promote(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        if ($row->submitted_at === null) {
            abort(422, 'The candidate has not submitted their requirements yet.');
        }
        if ($row->promoted_employee_id !== null) {
            abort(422, 'This candidate is already an employee.');
        }

        $data = $request->validate([
            'idNo' => ['required', 'string', 'max:20'],
            'department' => ['required', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
        ], [], ['idNo' => 'ID No.', 'jobTitle' => 'job title']);

        $idNo = trim($data['idNo']);
        if (preg_match(self::ID_NO, $idNo) !== 1) {
            abort(422, 'An ID No. is the start date and a number, like 261006-0021.');
        }
        $department = trim($data['department']);
        if ($department === '') {
            abort(422, 'Choose a department.');
        }
        if (Employee::query()->where('id_no', $idNo)->exists()) {
            abort(422, 'Someone already has the ID No. '.$idNo.'.');
        }
        $email = strtolower(trim((string) $row->email));
        if (Employee::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
            abort(422, 'Someone already has that email address.');
        }

        $details = $this->detailsOf($row);
        $employee = new Employee;
        $employee->forceFill([
            'id_no' => $idNo,
            'first_name' => $this->text($details['firstName'] ?? null) ?? 'New',
            'middle_name' => $this->text($details['middleName'] ?? null),
            'last_name' => $this->text($details['lastName'] ?? null) ?? 'Employee',
            'nickname' => $this->text($details['nickname'] ?? null),
            'email' => $email,
            'job_title' => $this->text($data['jobTitle'] ?? null),
            'department' => $department,
            'role' => $this->text($data['role'] ?? null) ?? 'User',
            'status' => 'Active',
            'start_date' => $this->text($details['startDate'] ?? null),
            'date_of_birth' => $this->text($details['dateOfBirth'] ?? null),
            'address' => $this->text($details['address'] ?? null),
            'phone_number' => $this->text($details['phoneNumber'] ?? null),
            'emergency_contact_person_name' => $this->text($details['emergencyContactPersonName'] ?? null),
            'emergency_contact_number' => $this->text($details['emergencyContactNumber'] ?? null),
            'emergency_contact_address' => $this->text($details['emergencyContactAddress'] ?? null),
            'tax_identification_no' => $this->text($details['taxIdentificationNo'] ?? null),
            'philhealth_no' => $this->text($details['philhealthNo'] ?? null),
            'sss_no' => $this->text($details['sssNo'] ?? null),
            'hdmf_no' => $this->text($details['hdmfNo'] ?? null),
            'bank_name' => $this->text($details['bankName'] ?? null),
            'bank_account_number' => $this->text($details['bankAccountNumber'] ?? null),
            'bank_swift_code' => $this->text($details['bankSwiftCode'] ?? null),
        ])->save();
        $employeeId = (int) $employee->getKey();

        $this->connection()->table('registrations')->where('id', $id)->update([
            'promoted_at' => now(),
            'promoted_employee_id' => $employeeId,
        ]);
        if ($row->employee_data_id !== null) {
            $this->connection()->table('new_employee_data')->where('id', (int) $row->employee_data_id)->update(['id_no' => $idNo, 'data_approval' => 'Approved']);
        }
        $this->audit->record($actor, PortalLogAction::INSERT, 'manage.onboarding', '{UserName|You} promoted '.trim((string) $row->candidate_name).' to employee '.$idNo, (string) $id, $request, ['idNo' => $idNo, 'department' => $department]);

        return ['employee' => [
            'id' => (string) $employeeId,
            'idNo' => $idNo,
            'name' => trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
        ]];
    }

    // ---------------------------------------------------------------- public sign-in

    /**
     * What the sign-in screen needs before a password is typed. Never the email itself.
     *
     * @return array{name: string, hasEmail: true}
     */
    public function info(string $code): array
    {
        $row = $this->rowByCode($code);

        return ['name' => (string) $row->candidate_name, 'hasEmail' => true];
    }

    /**
     * The password step: the right password mails a code to the bound address. There is no email
     * typing step: the code proves the mailbox the link belongs to.
     *
     * @return array{otpRequired: true}
     */
    public function access(Request $request, string $code): array
    {
        $row = $this->rowByCode($code);
        $this->checkPassword($row, (string) $request->input('password', ''));
        $this->sendOtp($row);

        return ['otpRequired' => true];
    }

    /**
     * The code step: both right, and the entry opens its session.
     *
     * @return array<string, mixed>
     */
    public function verify(Request $request, string $code): array
    {
        $row = $this->rowByCode($code);
        $this->checkPassword($row, (string) $request->input('password', ''));
        $this->checkOtp($row, (string) $request->input('otp', ''));

        return $this->issued($row);
    }

    /**
     * @return array{sent: true}
     */
    public function resend(Request $request, string $code): array
    {
        $row = $this->rowByCode($code);
        $this->checkPassword($row, (string) $request->input('password', ''));
        $this->sendOtp($row, true);

        return ['sent' => true];
    }

    /**
     * The stored session, re-read so the screen can resume where the candidate left off.
     *
     * @return array{name: string, status: string, details: array<string, mixed>, documents: list<array<string, mixed>>}
     */
    public function session(PortalRegistrationSession $session): array
    {
        $row = $this->row($session->registrationId);

        return [
            'name' => (string) $row->candidate_name,
            'status' => $this->statusOf($row),
            'details' => $this->detailsOf($row),
            'documents' => $this->documents->listFor((int) $row->id),
        ];
    }

    /**
     * The step-by-step autosave: only the fields sent are changed, merged into the draft.
     *
     * @return array{details: array<string, mixed>}
     */
    public function saveDetails(PortalRegistrationSession $session, Request $request): array
    {
        $row = $this->row($session->registrationId);
        if ($row->submitted_at !== null) {
            abort(422, 'This registration was already submitted.');
        }

        $rules = [];
        foreach (self::DETAIL_RULES as $field => $rule) {
            $rules[$field] = ['sometimes', 'nullable', 'string', ...explode('|', $rule)];
        }
        $data = $request->validate($rules);

        $details = $this->detailsOf($row);
        foreach ($data as $field => $value) {
            $clean = is_string($value) ? trim($value) : $value;
            $details[$field] = $clean === '' ? null : $clean;
        }
        $this->connection()->table('registrations')->where('id', (int) $row->id)
            ->update(['details' => json_encode($details)]);

        return ['details' => $details];
    }

    /**
     * Stages one document on the registration.
     *
     * @return array{document: array<string, mixed>}
     */
    public function storeDocument(PortalRegistrationSession $session, Request $request): array
    {
        $row = $this->row($session->registrationId);
        if ($row->submitted_at !== null) {
            abort(422, 'This registration was already submitted.');
        }

        return ['document' => $this->documents->store($row, $request)];
    }

    public function destroyDocument(PortalRegistrationSession $session, int $documentId): void
    {
        $row = $this->row($session->registrationId);
        if ($row->submitted_at !== null) {
            abort(422, 'This registration was already submitted.');
        }

        $this->documents->destroy((int) $row->id, $documentId);
    }

    /**
     * The last step: the required details and documents are checked, the row lands in
     * new_employee_data, and the administrator's entry turns Submitted.
     *
     * @return array{submitted: true}
     */
    public function submit(PortalRegistrationSession $session): array
    {
        $row = $this->row($session->registrationId);
        if ($row->submitted_at !== null) {
            abort(422, 'This registration was already submitted.');
        }

        $details = $this->detailsOf($row);
        $missing = [];
        foreach (self::REQUIRED_DETAILS as $field) {
            $value = $details[$field] ?? null;
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            abort(422, 'Complete every field before submitting.');
        }
        if ($this->documents->missingRequired((int) $row->id) !== []) {
            abort(422, 'Upload every required document before submitting.');
        }

        $employeeDataId = (int) $this->connection()->table('new_employee_data')->insertGetId([
            'id_no' => null,
            'first_name' => $this->text($details['firstName'] ?? null),
            'middle_name' => $this->text($details['middleName'] ?? null),
            'last_name' => $this->text($details['lastName'] ?? null),
            'nickname' => $this->text($details['nickname'] ?? null),
            'email' => strtolower(trim((string) $row->email)),
            'start_date' => $this->text($details['startDate'] ?? null),
            'date_of_birth' => $this->text($details['dateOfBirth'] ?? null),
            'status' => 'Active',
            'address' => $this->text($details['address'] ?? null),
            'phone_number' => $this->text($details['phoneNumber'] ?? null),
            'emergency_contact_person_name' => $this->text($details['emergencyContactPersonName'] ?? null),
            'emergency_contact_number' => $this->text($details['emergencyContactNumber'] ?? null),
            'emergency_contact_address' => $this->text($details['emergencyContactAddress'] ?? null),
            'tax_identification_no' => $this->text($details['taxIdentificationNo'] ?? null),
            'philhealth_no' => $this->text($details['philhealthNo'] ?? null),
            'sss_no' => $this->text($details['sssNo'] ?? null),
            'hdmf_no' => $this->text($details['hdmfNo'] ?? null),
            'bank_name' => $this->text($details['bankName'] ?? null),
            'bank_account_number' => $this->text($details['bankAccountNumber'] ?? null),
            'bank_swift_code' => $this->text($details['bankSwiftCode'] ?? null),
            'data_approval' => 'Pending',
        ]);
        $this->connection()->table('registrations')->where('id', (int) $row->id)->update([
            'submitted_at' => now(),
            'employee_data_id' => $employeeDataId,
        ]);

        return ['submitted' => true];
    }

    public function link(string $code): string
    {
        $base = rtrim((string) config('products.catalog.portal.public_url', ''), '/');

        return ($base === '' ? '' : $base).'/register/'.$code;
    }

    // ---------------------------------------------------------------- internals

    /**
     * @return array{candidateName: string, email: string, slug: ?string, expiresAt: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'candidateName' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'slug' => ['nullable', 'string', 'max:64'],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_EXPIRY_DAYS],
            'expiresAt' => ['nullable', 'date'],
            'neverExpires' => ['nullable', 'boolean'],
        ], [], ['candidateName' => 'candidate name']);

        $candidateName = trim($data['candidateName']);
        if ($candidateName === '') {
            abort(422, 'Give the candidate a name.');
        }

        return [
            'candidateName' => $candidateName,
            'email' => strtolower(trim($data['email'])),
            'slug' => PortalCredentials::slug($data['slug'] ?? null),
            'expiresAt' => $this->expiresAt($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expiresAt(array $data): ?string
    {
        if (($data['neverExpires'] ?? false) === true) {
            return null;
        }
        $expiresAt = $data['expiresAt'] ?? null;
        $days = (int) ($data['expiresInDays'] ?? self::DEFAULT_EXPIRY_DAYS);

        return $expiresAt !== null
            ? date('Y-m-d H:i:s', strtotime((string) $expiresAt))
            : now()->addDays($days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS)->toDateTimeString();
    }

    // The link's code: the typed slug when there is one, a generated one otherwise.
    private function codeFor(?string $slug, ?int $exceptId = null): string
    {
        if ($slug === null) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = PortalCredentials::code();
                if (! $this->connection()->table('registrations')->where('code', $code)->exists()) {
                    return $code;
                }
            }
            abort(500, 'Could not make a link code.');
        }

        $taken = $this->connection()->table('registrations')->where('code', $slug)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($taken) {
            abort(422, 'That link slug is already taken.');
        }

        return $slug;
    }

    private function row(int $id): object
    {
        $row = $this->connection()->table('registrations')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That registration was not found.');
        }

        return $row;
    }

    // The entry a link names. A revoked or expired one answers as a dead link, whatever the password.
    private function rowByCode(string $code): object
    {
        $row = $this->connection()->table('registrations')->where('code', $code)->first();
        if ($row === null || $row->revoked_at !== null) {
            abort(404, 'This link is not active.');
        }
        if ($row->expires_at !== null && strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(410, 'This link has expired.');
        }

        return $row;
    }

    private function checkPassword(object $registration, string $password): void
    {
        if (! Hash::check($password, (string) $registration->password_hash)) {
            abort(422, 'That password is not right.');
        }
    }

    private function checkOtp(object $registration, string $otp): void
    {
        $row = $this->connection()->table('register_otps')->where('registration_id', $registration->id)->first();
        if ($row === null || strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(422, 'Ask for a new code.');
        }
        if ((int) $row->attempts >= self::OTP_MAX_ATTEMPTS) {
            abort(422, 'Too many tries. Ask for a new code.');
        }
        if (! Hash::check($otp, (string) $row->code_hash)) {
            $this->connection()->table('register_otps')->where('registration_id', $registration->id)->increment('attempts');
            abort(422, 'That code is not right.');
        }
        $this->connection()->table('register_otps')->where('registration_id', $registration->id)->delete();
    }

    private function sendOtp(object $registration, bool $isResend = false): void
    {
        $existing = $this->connection()->table('register_otps')->where('registration_id', $registration->id)->first();
        if ($existing !== null && strtotime((string) $existing->created_at) > now()->getTimestamp() - self::OTP_RESEND_SECONDS && ! $isResend) {
            return; // the first code just went out; a second press cannot mint another
        }
        if ($existing !== null && $isResend && strtotime((string) $existing->created_at) > now()->getTimestamp() - self::OTP_RESEND_SECONDS) {
            abort(429, 'Wait a moment before asking for another code.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->connection()->table('register_otps')->updateOrInsert(
            ['registration_id' => $registration->id],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'created_at' => now(), 'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)],
        );
        Mail::to(strtolower(trim((string) $registration->email)))->send(new PortalEmailMail(
            'Your code for '.trim((string) $registration->candidate_name),
            'Your code is '.$code.'. It is good for '.self::OTP_TTL_MINUTES.' minutes.'
                ."\n\nOpen your registration: ".$this->link((string) $registration->code),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(object $registration): array
    {
        $ttl = $this->sessionTtl($registration);
        $token = $this->jwt->issue([
            'registration' => (int) $registration->id,
            'name' => (string) $registration->candidate_name,
        ], $ttl);
        if ($registration->opened_at === null) {
            $this->connection()->table('registrations')->where('id', $registration->id)->update(['opened_at' => now()]);
        }

        return [
            'token' => $token,
            'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            'name' => (string) $registration->candidate_name,
            'details' => $this->detailsOf($registration),
            'documents' => $this->documents->listFor((int) $registration->id),
        ];
    }

    private function sessionTtl(object $registration): int
    {
        if ($registration->expires_at === null) {
            return self::MAX_SESSION_TTL_SECONDS;
        }

        return max(60, min(strtotime((string) $registration->expires_at) - now()->getTimestamp(), self::MAX_SESSION_TTL_SECONDS));
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsOf(object $registration): array
    {
        $raw = $registration->details ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function statusOf(object $registration): string
    {
        if ($registration->revoked_at !== null) {
            return 'revoked';
        }
        if ($registration->promoted_at !== null) {
            return 'promoted';
        }
        if ($registration->submitted_at !== null) {
            return 'submitted';
        }
        if ($registration->expires_at !== null && strtotime((string) $registration->expires_at) <= now()->getTimestamp()) {
            return 'expired';
        }
        if ($registration->opened_at !== null) {
            return 'opened';
        }
        if ($registration->sent_at !== null) {
            return 'sent';
        }

        return 'created';
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'id' => (string) (int) $row->id,
            'candidateName' => (string) $row->candidate_name,
            'email' => (string) $row->email,
            'code' => (string) $row->code,
            'link' => $this->link((string) $row->code),
            // The readable password, so the administrator can send it again.
            'password' => $row->password_value === null ? null : (string) $row->password_value,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
            'isRevoked' => $row->revoked_at !== null,
            'status' => $this->statusOf($row),
            'sentAt' => $row->sent_at === null ? null : (string) $row->sent_at,
            'openedAt' => $row->opened_at === null ? null : (string) $row->opened_at,
            'submittedAt' => $row->submitted_at === null ? null : (string) $row->submitted_at,
            'promotedAt' => $row->promoted_at === null ? null : (string) $row->promoted_at,
            'promotedEmployeeId' => $row->promoted_employee_id === null ? null : (string) (int) $row->promoted_employee_id,
        ];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function connection(): Connection
    {
        return Employee::query()->getConnection();
    }
}
