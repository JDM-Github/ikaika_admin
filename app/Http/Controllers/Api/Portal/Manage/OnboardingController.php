<?php

namespace App\Http\Controllers\Api\Portal\Manage;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalPrivateFiles;
use App\Support\Portal\PortalRegistrations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manage / Onboarding: a candidate entry per registration link. Creating and reading the entries,
 * handing the draft to the composer, regenerating the password, revoking, and promoting a submitted
 * candidate into an employees row. Every answer rides in `data`, the portal's envelope.
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly PortalRegistrations $registrations) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->registrations->index()]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->detail($id)]);
    }

    public function qr(int $id): JsonResponse
    {
        return response()->json(['data' => ['svg' => $this->registrations->qr($id)]]);
    }

    public function document(int $id, int $documentId): StreamedResponse
    {
        $row = $this->registrations->document($id, $documentId);

        return PortalPrivateFiles::download($row['path'], $row['name'], $row['mime']);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->create($this->actor($request), $request)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->update($this->actor($request), $request, $id)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->revoke($this->actor($request), $request, $id)]);
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->regenerate($this->actor($request), $request, $id)]);
    }

    public function sent(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->markSent($this->actor($request), $request, $id)]);
    }

    public function promotion(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->promotion($this->actor($request), $id)]);
    }

    public function promote(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->registrations->promote($this->actor($request), $request, $id)]);
    }

    private function actor(Request $request): Employee
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $actor;
    }
}
