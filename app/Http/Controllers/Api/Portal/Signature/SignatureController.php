<?php

namespace App\Http\Controllers\Api\Portal\Signature;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Email signatures. `show` is the signed-in employee's own rendered signature (any member, on
 * Settings, where it is copied to paste into Outlook). Signatures are footers carrying the $SIGNATURE
 * token, edited with the email footers (the email.library power) on Manage / Signatures, which also
 * hands IT the Exchange rule scripts.
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

    // The footer everyone without their own assigned footer gets: Settings and the Exchange rules use it.
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
    // ?employeeIds=1,2,3 covers only those people; anyone left out loses their rule when it runs.
    public function transportRule(Request $request): JsonResponse
    {
        $ids = null;
        if ($request->query->has('employeeIds')) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string) $request->query('employeeIds'))),
                static fn (int $id): bool => $id > 0,
            )));
            if ($ids === []) {
                abort(422, 'Choose at least one person, or use the rule remover to take the signature off everyone.');
            }
        }
        $rule = $this->signatures->transportRule($ids);
        if ($rule === null) {
            abort(404, $ids === null ? 'No signature footer is set.' : 'None of the chosen people can carry a signature rule.');
        }

        return response()->json(['section' => 'signature', 'resource' => 'signature-transport-rule', 'data' => $rule]);
    }

    // Who the Exchange rules can cover, for the picker on Manage / Signatures.
    public function rulePeople(): JsonResponse
    {
        return response()->json(['section' => 'signature', 'resource' => 'signature-rule-people', 'data' => $this->signatures->rulePeople()]);
    }

    // The undo for transportRule: a script that removes every IKAIKA signature rule.
    public function transportRuleRemover(): JsonResponse
    {
        return response()->json(['section' => 'signature', 'resource' => 'signature-transport-rule-remover', 'data' => $this->signatures->transportRuleRemover()]);
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
