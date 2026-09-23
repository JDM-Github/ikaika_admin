<?php

namespace App\Support;

use App\Modules\Support\ProductModule;

/**
 * Catalog / playground nav: one shape for every product so the sidebar can mirror the routes.
 */
final class ProductNav
{
    /**
     * @param  array<string, mixed>  $config
     * @param  array{ok: bool, database: ?string, error: ?string}  $health
     * @return array<string, mixed>
     */
    public static function product(
        string $key,
        array $config,
        ?ProductModule $module,
        bool $enabled,
        array $health,
        bool $withCounts = false,
    ): array {
        $channel = (string) config('products.channel');
        $resources = $module?->resources() ?? [];

        return [
            'key' => $key,
            'name' => $config['name'] ?? $key,
            'description' => $config['description'] ?? null,
            'enabled' => $enabled,
            'connection' => $config['connection'] ?? null,
            'database' => $config['database'] ?? null,
            'health' => $health,
            'base_url' => ApiPath::publicPath($channel, $key),
            'auth' => self::operations($module?->authOperations() ?? [], $channel, $key),
            'utilities' => self::utilities($module, $channel, $key),
            'sections' => self::sections($module?->sections() ?? [], $channel, $key),
            'resources' => collect($resources)->map(function (array $resource, string $name) use ($channel, $key, $health, $withCounts) {
                $count = null;
                if ($withCounts && ($health['ok'] ?? false) && isset($resource['model'])) {
                    $count = $resource['model']::query()->count();
                }

                return [
                    'name' => $name,
                    'label' => $resource['label'] ?? $name,
                    'count' => $count,
                    'url' => ApiPath::publicPath($channel, $key, $name),
                    'method' => 'GET',
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return list<array<string, mixed>>
     */
    private static function sections(array $sections, string $channel, string $key): array
    {
        return collect($sections)->map(function (array $section, string $sectionName) use ($channel, $key) {
            return [
                'name' => $sectionName,
                'label' => $section['label'] ?? $sectionName,
                'requires_admin' => (bool) ($section['requires_admin'] ?? false),
                'resources' => collect($section['resources'] ?? [])->map(function (array $resource, string $name) use ($channel, $key) {
                    $path = $resource['path'] ?? $name;
                    $operations = $resource['operations'] ?? [
                        ['method' => 'GET', 'path' => $path, 'label' => $resource['label'] ?? $name],
                    ];

                    return [
                        'name' => $name,
                        'label' => $resource['label'] ?? $name,
                        'url' => ApiPath::publicPath($channel, $key, $path),
                        'requires_admin' => (bool) ($resource['requires_admin'] ?? false),
                        'operations' => self::operations($operations, $channel, $key),
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function utilities(?ProductModule $module, string $channel, string $key): array
    {
        $listed = self::operations($module?->utilities() ?? [], $channel, $key);
        $healthUrl = ApiPath::publicPath($channel, $key, 'health');
        $hasHealth = collect($listed)->contains(fn (array $op): bool => ($op['url'] ?? '') === $healthUrl);

        if (! $hasHealth) {
            array_unshift($listed, [
                'method' => 'GET',
                'label' => 'Health',
                'url' => $healthUrl,
                'auth' => false,
                'body' => null,
            ]);
        }

        return $listed;
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<array<string, mixed>>
     */
    private static function operations(array $operations, string $channel, string $key): array
    {
        return array_values(array_map(function (array $operation) use ($channel, $key) {
            $path = $operation['path'] ?? '';

            return [
                'method' => strtoupper((string) ($operation['method'] ?? 'GET')),
                'label' => $operation['label'] ?? ($operation['method'] ?? 'GET'),
                'url' => ApiPath::publicPath($channel, $key, $path),
                'auth' => (bool) ($operation['auth'] ?? false),
                'body' => $operation['body'] ?? null,
            ];
        }, $operations));
    }
}
