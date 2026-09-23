<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Workspace\WorkspaceSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceSchemaController extends Controller
{
    use ResolvesWorkspaceAccess;

    public function __construct(private readonly WorkspaceSchema $schema) {}

    public function index(Request $request, string $product): JsonResponse
    {
        $access = $this->access($request);

        return response()->json($this->schema->nav($product) + ['grants' => $access->grants()]);
    }

    public function show(Request $request, string $product, string $table): JsonResponse
    {
        $access = $this->access($request);

        return response()->json($this->schema->table($product, $table, $access));
    }
}
