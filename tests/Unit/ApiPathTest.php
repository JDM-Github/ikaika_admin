<?php

namespace Tests\Unit;

use App\Support\ApiPath;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ApiPathTest extends TestCase
{
    public function test_empty_prefix_keeps_the_local_api_root(): void
    {
        config(['products.path_prefix' => '']);

        $this->assertSame('', ApiPath::directory());
        $this->assertSame('api', ApiPath::routePrefix());
        $this->assertSame('/api', ApiPath::prefix());
        $this->assertSame('/api/development/core/health', ApiPath::join('development', 'core', 'health'));
        $this->assertSame('/api/development/core/health', ApiPath::publicPath('development', 'core', 'health'));
        $this->assertSame('/api/{channel}/{product}/{resource}', ApiPath::convention());
    }

    public function test_a_subdirectory_sits_in_front_of_api(): void
    {
        config(['products.path_prefix' => '/staging/central-api/']);

        $this->assertSame('staging/central-api', ApiPath::directory());
        $this->assertSame('staging/central-api/api', ApiPath::routePrefix());
        $this->assertSame(
            '/staging/central-api/api/staging/core/health',
            ApiPath::join('staging', 'core', 'health'),
        );
        $this->assertSame(
            '/staging/central-api/api/staging/core/health',
            ApiPath::publicPath('staging', 'core', 'health'),
        );
    }

    public function test_public_path_prepends_the_subdirectory_the_app_is_served_from(): void
    {
        config(['products.path_prefix' => '']);
        URL::useOrigin('https://ikaikabim.com/staging/central-api');

        try {
            $this->assertSame('/api', ApiPath::prefix());
            $this->assertSame(
                '/staging/central-api/api/staging/core/health',
                ApiPath::publicPath('staging', 'core', 'health'),
            );
        } finally {
            URL::useOrigin(null);
        }
    }

    public function test_public_path_does_not_double_a_configured_prefix(): void
    {
        config(['products.path_prefix' => 'staging/central-api']);
        URL::useOrigin('https://ikaikabim.com/staging/central-api');

        try {
            $this->assertSame(
                '/staging/central-api/api/staging/core/health',
                ApiPath::publicPath('staging', 'core', 'health'),
            );
        } finally {
            URL::useOrigin(null);
        }
    }
}
