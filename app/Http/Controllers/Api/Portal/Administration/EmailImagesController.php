<?php

namespace App\Http\Controllers\Api\Portal\Administration;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Models\Employee;
use App\Support\Portal\PortalEmailImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailImagesController extends Controller
{
    public function __construct(
        private readonly PortalEmailImages $images,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->images->list($this->actor($request), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->images->store($this->actor($request), $request), 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->images->destroy($this->actor($request), $request, $id);

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
