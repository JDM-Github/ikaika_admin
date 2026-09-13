<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalRecycleBin;
use App\Support\Portal\PortalSubmittedReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecycleBinController extends Controller
{
    public function __construct(
        private readonly PortalRecycleBin $recycleBin,
        private readonly PortalSubmittedReports $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->recycleBin->list($actor, $request));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->recycleBin->show($actor, $id));
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        $row = $this->recycleBin->itemForActor($actor, $id);
        $this->reports->restoreFromBin($actor, $row, $request);

        return response()->json(null, 204);
    }
}
