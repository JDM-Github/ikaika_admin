<?php

namespace App\Support;

use Illuminate\Http\Request;

class ApiPath
{
    /**
     * Directory in front of /api, with no slashes. Empty locally.
     */
    public static function directory(): string
    {
        return trim((string) config('products.path_prefix', ''), '/');
    }

    /**
     * Laravel route prefix: "api" or "staging/central-api/api".
     */
    public static function routePrefix(): string
    {
        $directory = self::directory();

        return $directory === '' ? 'api' : $directory.'/api';
    }

    /**
     * Leading-slash URL root: "/api" or "/staging/central-api/api".
     */
    public static function prefix(): string
    {
        return '/'.self::routePrefix();
    }

    public static function join(string ...$segments): string
    {
        $parts = [];
        foreach ($segments as $segment) {
            $trimmed = trim($segment, '/');
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return self::prefix().($parts === [] ? '' : '/'.implode('/', $parts));
    }

    public static function convention(): string
    {
        return self::prefix().'/{channel}/{product}/{resource}';
    }

    /**
     * Browser subdirectory in front of /api. Empty locally.
     * Laravel routes stay /api/... ; the browser must call /staging/central-api/api/...
     */
    public static function browserMount(): string
    {
        if (app()->bound('request')) {
            $base = rtrim(str_replace('\\', '/', (string) request()->getBasePath()), '/');
            if ($base !== '') {
                return $base;
            }
        }

        $fromUrl = parse_url((string) url('/'), PHP_URL_PATH);

        return rtrim((string) $fromUrl, '/');
    }

    /**
     * Path the browser should fetch. A leading /api/... is origin-absolute and hits
     * WordPress at the domain root, so this prepends where the page is mounted.
     */
    public static function publicPath(string ...$segments): string
    {
        $routePath = $segments === [] ? self::prefix() : self::join(...$segments);
        $mount = self::browserMount();

        if ($mount === '' || str_starts_with($routePath, $mount.'/') || $routePath === $mount) {
            return $routePath;
        }

        return $mount.$routePath;
    }

    public static function isApiRequest(Request $request): bool
    {
        $prefix = self::routePrefix();

        return $request->is($prefix) || $request->is($prefix.'/*');
    }
}
