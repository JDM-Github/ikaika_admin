<?php

namespace App\Http\Controllers\Api\Portal\Register;

use App\Http\Controllers\Controller;
use App\Support\Portal\PortalPrivateFiles;
use App\Support\Portal\PortalRegistrations;
use App\Support\Portal\PortalRegistrationSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public side of a registration link: the password and the mailed code open a session, and the
 * session routes autosave the candidate's details, take their documents and submit. Every answer
 * rides in `data`, the portal's envelope.
 */
class RegisterController extends Controller
{
    public function __construct(private readonly PortalRegistrations $registrations) {}

    public function info(string $code): JsonResponse
    {
        return response()->json(['data' => $this->registrations->info($code)]);
    }

    public function access(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->registrations->access($request, $code)]);
    }

    public function verify(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->registrations->verify($request, $code)]);
    }

    public function resend(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->registrations->resend($request, $code)]);
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->session($this->current($request))]);
    }

    public function details(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->saveDetails($this->current($request), $request)]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->storeDocument($this->current($request), $request)]);
    }

    // The candidate's own file, so the form can show what they sent (2026-10-12).
    public function showDocument(Request $request, int $id): StreamedResponse
    {
        $row = $this->registrations->ownDocument($this->current($request), $id);

        return PortalPrivateFiles::download($row['path'], $row['name'], $row['mime']);
    }

    public function destroyDocument(Request $request, int $id): JsonResponse
    {
        $this->registrations->destroyDocument($this->current($request), $id);

        return response()->json(null, 204);
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->submit($this->current($request))]);
    }

    private function current(Request $request): PortalRegistrationSession
    {
        $session = $request->attributes->get('portalRegistration');
        if (! $session instanceof PortalRegistrationSession) {
            abort(401, 'Authentication is required.');
        }

        return $session;
    }
}
