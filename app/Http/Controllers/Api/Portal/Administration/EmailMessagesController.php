<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmailAudience;
use App\Support\Portal\PortalEmailMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailMessagesController extends Controller
{
    public function __construct(
        private readonly PortalEmailMessages $messages,
        private readonly PortalEmailAudience $audience,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->messages->list($request));
    }

    public function audiences(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->audience->options());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->messages->show($id));
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->messages->send($actor, $request), 201);
    }
}
