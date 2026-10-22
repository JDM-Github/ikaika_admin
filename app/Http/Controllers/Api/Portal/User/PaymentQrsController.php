<?php

namespace App\Http\Controllers\Api\Portal\User;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalLogAction;
use App\Support\Portal\PortalPaymentQrs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The signed-in member's own payment QR codes on Profile (2026-10-21, user).
class PaymentQrsController extends Controller
{
    public function __construct(
        private readonly PortalPaymentQrs $qrs,
        private readonly PortalAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['section' => 'user', 'resource' => 'payment-qrs', 'data' => $this->qrs->mine($this->actor($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $qr = $this->qrs->store($actor, $request);
        $this->audit->record($actor, PortalLogAction::INSERT, 'user.payment-qrs', '{UserName|You} saved a '.$qr['bankLabel'].' payment QR', $qr['id'], $request);

        return response()->json(['section' => 'user', 'resource' => 'payment-qrs', 'data' => $qr], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $qr = $this->qrs->remove($actor, $id);
        $this->audit->record($actor, PortalLogAction::DELETE, 'user.payment-qrs', '{UserName|You} removed a '.$qr['bankLabel'].' payment QR', $qr['id'], $request);

        return response()->json(['section' => 'user', 'resource' => 'payment-qrs', 'data' => $qr]);
    }

    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee || $request->attributes->has('portalShare')) {
            abort(403, 'Only a signed-in employee keeps payment QR codes.');
        }

        return $actor;
    }
}
