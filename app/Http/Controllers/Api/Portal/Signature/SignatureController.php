<?php

namespace App\Http\Controllers\Api\Portal\Signature;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email signatures. `show` is the signed-in employee's own rendered signature (any member, on
 * Settings); `document` is the same as a downloadable .htm. Signatures are footers carrying the
 * $SIGNATURE token, edited with the email footers (the email.library power) on Manage / Signatures.
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

    // The employee's signature as a downloadable .htm, for Outlook.
    public function document(Request $request): JsonResponse
    {
        $document = $this->signatures->document($this->employee($request));
        if ($document === null) {
            abort(404, 'No signature footer is set.');
        }

        return response()->json(['section' => 'signature', 'resource' => 'signature-document', 'data' => $document]);
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
