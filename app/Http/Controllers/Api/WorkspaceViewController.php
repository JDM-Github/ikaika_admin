<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Workspace\WorkspaceSchema;
use App\Support\Workspace\WorkspaceViews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceViewController extends Controller
{
    use ResolvesWorkspaceAccess;

    public function __construct(
        private readonly WorkspaceViews $views,
        private readonly WorkspaceSchema $schema,
    ) {}

    public function index(Request $request, string $product, string $table): JsonResponse
    {
        $access = $this->access($request);
        $this->schema->assertBrowsable($product, $table);

        return response()->json([
            'product' => $product,
            'table' => $table,
            'views' => $this->views->list($product, $table, $access),
        ]);
    }

    public function store(Request $request, string $product, string $table): JsonResponse
    {
        $access = $this->access($request);
        $this->schema->assertBrowsable($product, $table);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'query' => ['present', 'string', 'max:2000'],
            'shared' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->views->save(
            $product,
            $table,
            $validated['name'],
            $validated['query'],
            (bool) ($validated['shared'] ?? false),
            $access,
        ), 201);
    }

    public function destroy(Request $request, string $product, int $id): JsonResponse
    {
        $this->views->delete($id, $this->access($request));

        return response()->json(['deleted' => $id]);
    }
}
