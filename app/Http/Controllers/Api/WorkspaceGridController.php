<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Workspace\WorkspaceGrid;
use App\Support\Workspace\WorkspaceWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceGridController extends Controller
{
    use ResolvesWorkspaceAccess;

    public function __construct(
        private readonly WorkspaceGrid $grid,
        private readonly WorkspaceWriter $writer,
    ) {}

    public function index(Request $request, string $product, string $table): JsonResponse
    {
        return response()->json(
            $this->grid->rows($product, $table, $request, $this->access($request)),
        );
    }

    public function show(Request $request, string $product, string $table, string $id): JsonResponse
    {
        return response()->json(
            $this->grid->record($product, $table, $id, $this->access($request)),
        );
    }

    /**
     * Correct scalar values on one record. `expect` carries what the editor last saw,
     * so a row someone else has moved on is refused rather than overwritten.
     */
    public function update(Request $request, string $product, string $table, string $id): JsonResponse
    {
        $access = $this->access($request);

        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'expect' => ['sometimes', 'array'],
        ]);

        return response()->json($this->writer->update(
            $product,
            $table,
            $id,
            (array) $validated['changes'],
            (array) ($validated['expect'] ?? []),
            $access,
        ));
    }
}
