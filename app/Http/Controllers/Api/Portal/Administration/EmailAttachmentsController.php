<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmailAttachments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Send Email's files: staged one at a time as they are attached, and taken back when removed.
class EmailAttachmentsController extends Controller
{
    public function __construct(
        private readonly PortalEmailAttachments $attachments,
    ) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->attachments->stage($this->actor($request), $request), 201);
    }

    public function destroy(Request $request, string $token): Response
    {
        $this->attachments->discard($this->actor($request), $token);

        return response()->noContent();
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
