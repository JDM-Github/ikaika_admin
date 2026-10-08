<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalAirtableSyncPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AirtableSyncController extends Controller
{
    public function __construct(private readonly PortalAirtableSyncPreview $preview) {}

    public function preview(Request $request): JsonResponse
    {
        $actor = $request->attributes->get('portalEmployee');
        if (! $actor instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return response()->json($this->preview->preview($actor, $request));
    }
}
