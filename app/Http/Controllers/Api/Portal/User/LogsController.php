<?php

namespace App\Http\Controllers\Api\Portal\User;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalUserLogPresenter;
use App\Support\Portal\PortalUserLogs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogsController extends Controller
{
    public function __construct(private readonly PortalUserLogs $logs) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->logs->list($actor, $request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json(PortalUserLogPresenter::withObjectPayload($this->logs->show($actor, $id)));
    }
}
