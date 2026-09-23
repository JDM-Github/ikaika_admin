<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProductRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function index(Request $request, string $product, string $resource): JsonResponse
    {
        $definition = $this->definition($product, $resource);
        $query = $this->query($definition);

        if ($search = trim((string) $request->query('q', ''))) {
            $columns = $definition['searchable'] ?? [];
            if ($columns !== []) {
                $query->where(function (Builder $builder) use ($columns, $search) {
                    foreach ($columns as $column) {
                        $builder->orWhere($column, 'like', "%{$search}%");
                    }
                });
            }
        }

        $perPage = min(100, max(1, $request->integer('per_page', 20)));
        $page = $query->orderBy('id')->paginate($perPage)->appends($request->query());

        return response()->json([
            'product' => $product,
            'resource' => $resource,
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $product, string $resource, string $id): JsonResponse
    {
        $definition = $this->definition($product, $resource);
        $query = $this->query($definition);

        if (! empty($definition['with'])) {
            $query->with($definition['with']);
        }

        $record = $query->findOrFail($id);

        return response()->json([
            'product' => $product,
            'resource' => $resource,
            'data' => $record,
        ]);
    }

    /**
     * @return array{model: class-string<Model>, label?: string, with?: list<string>, searchable?: list<string>}
     */
    private function definition(string $product, string $resource): array
    {
        $module = ProductRegistry::module($product);

        if ($module === null) {
            abort(404, "Product [{$product}] has no module.");
        }

        $resources = $module->resources();

        if (! isset($resources[$resource])) {
            abort(404, "Unknown resource [{$resource}] on product [{$product}].");
        }

        return $resources[$resource];
    }

    /**
     * @param  array{model: class-string<Model>}  $definition
     */
    private function query(array $definition): Builder
    {
        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $model::query();
    }
}
