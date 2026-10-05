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

    // How a footer body reads for the editor's sample person, before it is saved.
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'format' => ['required', 'string', 'in:markup,html'],
        ]);

        return response()->json([
            'section' => 'signature',
            'resource' => 'signature-preview',
            'data' => ['html' => $this->signatures->preview($this->employee($request), $data['body'], $data['format'])],
        ]);
    }

    // A one-click Windows Outlook installer (.cmd) for the employee's own signature.
    public function installer(Request $request): JsonResponse
    {
        $installer = $this->signatures->installer($this->employee($request));
        if ($installer === null) {
            abort(404, 'No signature footer is set.');
        }

        return response()->json(['section' => 'signature', 'resource' => 'signature-installer', 'data' => $installer]);
    }

    // The footer everyone without their own assigned footer gets: Settings, the installer and the
    // Exchange rules all use it.
    public function defaultFooter(): JsonResponse
    {
        return response()->json([
            'section' => 'signature',
            'resource' => 'signature-default',
            'data' => ['footerId' => $this->signatures->defaultFooterId()],
        ]);
    }

    public function setDefaultFooter(Request $request): JsonResponse
    {
        $data = $request->validate(['footerId' => ['required', 'integer', 'min:1']]);

        return response()->json([
            'section' => 'signature',
            'resource' => 'signature-default',
            'data' => ['footerId' => $this->signatures->setDefault($this->employee($request), (int) $data['footerId'], $request)],
        ]);
    }

    // A PowerShell script for IT: one Exchange transport rule per active employee, each appending that
    // person's signature server-side, locked and reaching every client including the new Outlook.
    public function transportRule(): JsonResponse
    {
        $rule = $this->signatures->transportRule();
        if ($rule === null) {
            abort(404, 'No signature footer is set.');
        }

        return response()->json(['section' => 'signature', 'resource' => 'signature-transport-rule', 'data' => $rule]);
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
