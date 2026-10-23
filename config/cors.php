<?php

use App\Support\ApiVersion;

/*
 * Cross-origin requests (2026-10-21, user): the framework's defaults, published for one change - a page on another
 * origin, the desktop app's app://portal or local Expo web, may read which backend answered. The web builds call the
 * API on their own origin and read the headers anyway.
 *
 * The paths are the API's own, as ApiPath::routePrefix() builds them. This file is read before products.php, so it
 * reads API_PATH_PREFIX itself; tests/Feature/ApiPathPrefixTest.php keeps the two in step.
 */

$directory = trim((string) env('API_PATH_PREFIX', ''), '/');

return [

    'paths' => [($directory === '' ? 'api' : $directory.'/api').'/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [ApiVersion::VERSION_HEADER, ApiVersion::CHANNEL_HEADER],

    'max_age' => 0,

    'supports_credentials' => false,

];
