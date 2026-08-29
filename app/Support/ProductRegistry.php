<?php

namespace App\Support;

use App\Modules\Support\ProductModule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ProductRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return config('products.catalog', []);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function enabled(): array
    {
        return array_filter(
            self::catalog(),
            fn (array $product): bool => (bool) ($product['enabled'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        $product = self::catalog()[$key] ?? null;

        if ($product === null) {
            abort(404, "Unknown product [{$key}].");
        }

        if (! ($product['enabled'] ?? false)) {
            abort(503, "Product [{$key}] is registered but not enabled.");
        }

        return $product + ['key' => $key];
    }

    public static function module(string $key): ?ProductModule
    {
        $class = self::get($key)['module'] ?? null;

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        $module = app($class);

        if (! $module instanceof ProductModule) {
            throw new RuntimeException("{$class} must implement ".ProductModule::class);
        }

        return $module;
    }

    public static function registerConnections(): void
    {
        $mysql = config('database.connections.mysql', []);

        foreach (self::catalog() as $product) {
            $name = $product['connection'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            Config::set("database.connections.{$name}", array_merge($mysql, [
                'driver' => $product['driver'] ?? 'mysql',
                'host' => $product['host'] ?? ($mysql['host'] ?? '127.0.0.1'),
                'port' => $product['port'] ?? ($mysql['port'] ?? '3306'),
                'database' => $product['database'] ?? null,
                'username' => $product['username'] ?? ($mysql['username'] ?? 'root'),
                'password' => $product['password'] ?? ($mysql['password'] ?? ''),
            ]));
        }
    }

    /**
     * @return array{ok: bool, database: ?string, error: ?string}
     */
    public static function ping(string $key): array
    {
        $product = self::get($key);
        $connection = $product['connection'];

        try {
            $pdo = DB::connection($connection)->getPdo();

            return [
                'ok' => true,
                'database' => $product['database'] ?? $pdo->query('select database()')->fetchColumn(),
                'error' => null,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'database' => $product['database'] ?? null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
