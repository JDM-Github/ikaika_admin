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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAuthController extends Controller
{
    public function __construct(
        private readonly PortalJwt $jwt,
        private readonly PortalAudit $audit,
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

        $status = strtolower(trim((string) $employee->status));
        if ($status !== 'active') {
            abort(401, 'This account is not active.');
        }

        $ttl = $this->jwt->ttlSeconds();
        $token = $this->jwt->issue([
            'sub' => (string) $employee->getKey(),
            'id_no' => $employee->id_no,
            'role' => $employee->role,
            'status' => $employee->status,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'department' => $employee->department,
            'job_title' => $employee->job_title,
            'email' => $employee->email,
        ]);

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
}
