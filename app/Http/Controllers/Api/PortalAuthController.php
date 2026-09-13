<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalActivityCopy;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalEmployeePresenter;
use App\Support\Portal\PortalJwt;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalManageUserPresenter;
use App\Support\Portal\PortalMicrosoftIdToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalAuthController extends Controller
{
    public function __construct(
        private readonly PortalJwt $jwt,
        private readonly PortalAudit $audit,
        private readonly PortalMicrosoftIdToken $microsoftIdToken,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_no' => ['required', 'string', 'max:64'],
        ]);

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

        $employee = $this->employeeForMicrosoftName($claims['name_pairs']);
        if ($employee === null) {
            abort(401, $this->unmatchedMicrosoftNameMessage($claims['name_pairs']));
        }

        return $this->issueSession($employee, $request, $claims['oid']);
    }

    /**
     * @param  list<array{given: string, family: string}>  $pairs
     */
    private function employeeForMicrosoftName(array $pairs): ?Employee
    {
        if ($pairs === []) {
            return null;
        }

        $matches = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $index => $pair) {
                    $clause = function ($inner) use ($pair): void {
                        $inner->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower($pair['given'])])
                            ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower($pair['family'])]);
                    };
                    if ($index === 0) {
                        $query->where($clause);
                    } else {
                        $query->orWhere($clause);
                    }
                }
            })
            ->get()
            ->unique('id')
            ->values();

        if ($matches->count() > 1) {
            abort(401, 'That Microsoft name matches more than one portal account.');
        }

        return $matches->first();
    }

    /**
     * @param  list<array{given: string, family: string}>  $pairs
     */
    private function unmatchedMicrosoftNameMessage(array $pairs): string
    {
        $checked = [];
        foreach ($pairs as $pair) {
            $checked[] = $pair['given'].' / '.$pair['family'];
        }

        return 'No portal account matches that Microsoft account. Checked first/last: '.implode('; ', $checked).'.';
    }

    public function me(Request $request): JsonResponse
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json([
            'employee' => PortalEmployeePresenter::publicEmployee($employee),
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
            'employee' => PortalEmployeePresenter::publicEmployee($employee),
        ]);
    }
}
