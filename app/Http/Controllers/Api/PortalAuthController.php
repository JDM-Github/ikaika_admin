<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalAdvancedSettings;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalEmailLogin;
use App\Support\Portal\PortalEmployeePresenter;
use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalManageUserPresenter;
use App\Support\Portal\PortalMicrosoftIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalAuthController extends Controller
{
    public function __construct(
        private readonly PortalJwt $jwt,
        private readonly PortalAudit $audit,
        private readonly PortalMicrosoftIdToken $microsoftIdToken,
    ) {}

    // ID-number sign-in is a development aid (2026-10-12, user): local and the test suite only.
    public function login(Request $request): JsonResponse
    {
        // A deployed portal accepts ID sign-in only while the Advanced settings switch offers it.
        $deployed = ! app()->environment(['local', 'testing']);
        if ($deployed && ! PortalAdvancedSettings::idLogin()) {
            abort(404);
        }

        $validated = $request->validate([
            'id_no' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        // Deployed, it also needs the password only MIS holds; with none configured nobody gets in.
        if ($deployed) {
            $expected = (string) config('services.portal_id_login.password', '');
            if ($expected === '' || ! hash_equals($expected, (string) ($validated['password'] ?? ''))) {
                abort(401, 'Unknown ID number.');
            }
        }

        $idNo = trim($validated['id_no']);
        if ($idNo === '') {
            abort(401, 'Unknown ID number.');
        }

        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->where('id_no', $idNo)
            ->first();
        if ($employee === null) {
            abort(401, 'Unknown ID number.');
        }

        return $this->issueSession($employee, $request);
    }

    public function loginMicrosoft(Request $request): JsonResponse
    {
        if (! $this->microsoftIdToken->isConfigured()) {
            abort(503, 'Microsoft sign-in is not configured.');
        }

        $validated = $request->validate([
            'id_token' => ['required_without:code', 'string', 'max:16384'],
            'code' => ['required_without:id_token', 'string', 'max:8192'],
            'code_verifier' => ['required_with:code', 'string', 'min:43', 'max:128'],
            'redirect_uri' => ['required_with:code', 'string', 'url', 'max:2048'],
        ]);

        $idToken = $validated['id_token'] ?? null;
        if (isset($validated['code'], $validated['code_verifier'], $validated['redirect_uri'])) {
            $idToken = $this->microsoftIdToken->exchangeCode(
                $validated['code'],
                $validated['code_verifier'],
                $validated['redirect_uri'],
            );
        }

        if (! is_string($idToken) || $idToken === '') {
            abort(401, 'Microsoft sign-in failed.');
        }

        $claims = $this->microsoftIdToken->parse($idToken);
        if ($claims === null) {
            Log::warning('portal.azure.id_token_invalid');
            abort(401, 'Microsoft sign-in failed.');
        }

        $oid = $claims['oid'];
        if ($oid === null || ! Str::isUuid($oid)) {
            abort(401, 'Microsoft sign-in failed.');
        }
        $oid = strtolower(trim($oid));

        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->where('microsoft_oid', $oid)
            ->first();
        if ($employee !== null) {
            return $this->issueSession($employee, $request, $oid);
        }

        if (! PortalAdvancedSettings::microsoftFallback()) {
            abort(401, 'This Microsoft account is not linked to a portal account.');
        }

        $employee = $this->employeeForMicrosoftEmail($claims['emails']);
        if ($employee === null) {
            abort(401, 'This Microsoft account is not linked to a portal account.');
        }

        $claimed = Employee::query()
            ->whereKey($employee->getKey())
            ->whereNull('microsoft_oid')
            ->update(['microsoft_oid' => $oid]);
        if ($claimed !== 1) {
            // Another request won the race; only continue if it stored this same oid.
            $employee = Employee::query()
                ->select(PortalManageUserPresenter::sessionColumns())
                ->where('microsoft_oid', $oid)
                ->first();
            if ($employee === null) {
                abort(401, 'This Microsoft account is not linked to a portal account.');
            }
        } else {
            $employee->refresh();
            Log::info('portal.microsoft_fallback.linked', ['employee_id' => $employee->getKey()]);
        }

        return $this->issueSession($employee, $request, $oid);
    }

    /**
     * The one active, unlinked employee whose work email/UPN exactly matches a signed claim.
     * More than one match means the claims are ambiguous, so the migration refuses.
     *
     * @param  list<string>  $emails
     */
    private function employeeForMicrosoftEmail(array $emails): ?Employee
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn (string $email): string => strtolower(trim($email)), $emails),
            static fn (string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));
        if ($normalized === []) {
            return null;
        }

        $employees = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNull('microsoft_oid')
            ->where(function ($query) use ($normalized): void {
                foreach ($normalized as $email) {
                    $query->orWhereRaw('LOWER(TRIM(email)) = ?', [$email]);
                }
            })
            ->get()
            ->unique('id')
            ->values();

        if ($employees->count() > 1) {
            abort(401, 'That Microsoft email matches more than one portal account.');
        }

        return $employees->first();
    }

    // Email sign-in, step one: a code to each address on record, and where they went (masked).
    public function emailStart(Request $request, PortalEmailLogin $login): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'max:255']]);

        return response()->json(['targets' => $login->start($validated['email'])]);
    }

    // Email sign-in, step two: every code back, then the same session the other sign-ins issue.
    public function emailVerify(Request $request, PortalEmailLogin $login): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'codes' => ['required', 'array'],
            'codes.*' => ['nullable', 'string', 'max:12'],
        ]);

        return $this->issueSession($login->verify($validated['email'], $validated['codes']), $request);
    }

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json([
            'employee' => PortalEmployeePresenter::publicEmployee($employee, $request),
        ]);
    }

    private function issueSession(Employee $employee, Request $request, ?string $oid = null): JsonResponse
    {
        $status = strtolower(trim((string) $employee->status));
        if ($status !== 'active') {
            abort(401, 'This account is not active.');
        }

        $ttl = $this->jwt->ttlSeconds();
        $claims = [
            'sub' => (string) $employee->getKey(),
            'id_no' => $employee->id_no,
            'role' => $employee->role,
            'status' => $employee->status,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'department' => $employee->department,
            'job_title' => $employee->job_title,
            'email' => $employee->email,
        ];
        if ($oid !== null && $oid !== '') {
            $claims['oid'] = $oid;
        }

        $token = $this->jwt->issue($claims);

        $this->audit->record(
            $employee,
            PortalLogAction::POST,
            'auth.login',
            PortalActivityCopy::signedIn(),
            (string) $employee->getKey(),
            $request,
        );

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            'employee' => PortalEmployeePresenter::publicEmployee($employee, $request),
        ]);
    }
}
