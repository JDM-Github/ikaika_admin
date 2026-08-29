<?php

use App\Modules\Portal\PortalModule;
use App\Modules\ProjectEstimator\ProjectEstimatorModule;

/**
 * Product catalog for the central platform.
 *
 * Route shape:  /api/{channel}/{product}/{resource}
 * Example:      /api/development/portal/employees
 *
 * Adding a new product (e.g. project-estimator):
 *   1. Create a MySQL database and put its name in .env
 *   2. Add an entry below with its own connection name and database
 *   3. Create App\Modules\{Name}\ with a *Module class + Models
 *      (same short class names are fine — namespaces keep them apart)
 *   4. Point `module` at that class and set enabled=true
 */

return [

    'channel' => env('API_CHANNEL', 'development'),

    /*
     * Portal login issues an HS256 JWT. Claims carry the employee id and role so the
     * app does not send an id on every call; the signature is what the API trusts.
     */
    'jwt_secret' => env('PORTAL_JWT_SECRET', env('APP_KEY')),
    'jwt_ttl' => (int) env('PORTAL_JWT_TTL', 28800),

    'catalog' => [

        'core' => [
            'name' => 'Core Platform',
            'description' => 'Shared identities, teams, roles, files, and audit.',
            'connection' => 'core',
            'driver' => env('CORE_DB_DRIVER', 'mysql'),
            'host' => env('CORE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('CORE_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('CORE_DB_DATABASE', 'ikaika_platform'),
            'username' => env('CORE_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('CORE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'enabled' => true,
            'module' => null,
        ],

        'portal' => [
            'name' => 'Employee Portal',
            'description' => 'Employees, projects, reports, requests, and related records.',
            'connection' => 'portal',
            'driver' => env('PORTAL_DB_DRIVER', 'mysql'),
            'host' => env('PORTAL_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('PORTAL_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('PORTAL_DB_DATABASE', 'test_portal_database'),
            'username' => env('PORTAL_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('PORTAL_DB_PASSWORD', env('DB_PASSWORD', '')),
            'enabled' => filter_var(env('PORTAL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'module' => PortalModule::class,
        ],

        'project-estimator' => [
            'name' => 'Project Estimator',
            'description' => 'Placeholder product. Enable when its SQL database exists.',
            'connection' => 'project_estimator',
            'driver' => env('ESTIMATOR_DB_DRIVER', 'mysql'),
            'host' => env('ESTIMATOR_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('ESTIMATOR_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('ESTIMATOR_DB_DATABASE', 'test_estimator_database'),
            'username' => env('ESTIMATOR_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('ESTIMATOR_DB_PASSWORD', env('DB_PASSWORD', '')),
            'enabled' => filter_var(env('ESTIMATOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'module' => ProjectEstimatorModule::class,
        ],

    ],

];
