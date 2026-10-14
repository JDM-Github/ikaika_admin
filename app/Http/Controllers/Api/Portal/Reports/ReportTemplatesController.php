<?php

namespace App\Http\Controllers\Api\Portal\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalReportTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The report forms' saved templates: each person's own, read and written only by them. Every answer
 * rides bare at the top level, the way the email blocks do ({section, resource, data}).
 */
class ReportTemplatesController extends Controller
{
    public function __construct(private readonly PortalReportTemplates $templates) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->templates->list($this->actor($request)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->templates->create($this->actor($request), $request), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json($this->templates->update($this->actor($request), $request, $id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->templates->destroy($this->actor($request), $id);

        return response()->json(null, 204);
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
