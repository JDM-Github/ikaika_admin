<?php

namespace App\Http\Controllers\Api\Portal\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalSubmittedReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlaggedController extends Controller
{
    public function __construct(private readonly PortalSubmittedReports $reports) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->reports->listFlagged($actor, $request));
    }
}
