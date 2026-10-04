<?php

namespace App\Http\Controllers\Api\Portal\Signature;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email signatures. `show` is the signed-in employee's own rendered signature (any member, on
 * Settings); the rest edit the company templates behind the signature.template power.
 */
class SignatureController extends Controller
{
    public function __construct(private readonly PortalSignature $signatures) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'section' => 'signature',
            'resource' => 'signature',
            'data' => $this->signatures->render($this->employee($request)),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->signatures->index());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->signatures->addTemplate($this->employee($request), $request));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->signatures->updateTemplate($this->employee($request), $request, $id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json($this->signatures->removeTemplate($this->employee($request), $request, $id));
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->attributes->get('portalEmployee');
        if (! $employee instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $employee;
    }
}
