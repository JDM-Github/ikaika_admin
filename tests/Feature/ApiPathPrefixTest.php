<?php

namespace Tests\Feature;

use App\Support\ApiPath;
use App\Support\ApiVersion;
use Illuminate\Support\Env;
use Tests\TestCase;

class ApiPathPrefixTest extends TestCase
{
    public function createApplication()
    {
        $_ENV['API_PATH_PREFIX'] = 'staging/central-api';
        $_SERVER['API_PATH_PREFIX'] = 'staging/central-api';
        putenv('API_PATH_PREFIX=staging/central-api');
        Env::getRepository()->set('API_PATH_PREFIX', 'staging/central-api');

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        Env::getRepository()->set('API_PATH_PREFIX', '');
        $_ENV['API_PATH_PREFIX'] = '';
        $_SERVER['API_PATH_PREFIX'] = '';
        putenv('API_PATH_PREFIX=');

        parent::tearDown();
    }

    public function test_health_lives_under_the_configured_path_prefix(): void
    {
        $this->assertSame('staging/central-api', (string) config('products.path_prefix'));
        $this->assertSame('staging/central-api/api', ApiPath::routePrefix());
        $this->assertSame('/staging/central-api', config('app.asset_url'));

        $this->getJson('/staging/central-api/api/development/core/health')
            ->assertOk()
            ->assertJsonPath('product', 'core');

        $this->getJson('/api/development/core/health')
            ->assertNotFound();
    }

    // config/cors.php reads API_PATH_PREFIX itself (it loads before products.php), so the prefixed API still exposes them.
    public function test_another_origin_may_read_the_version_headers_under_the_prefix(): void
    {
        $this->getJson('/staging/central-api/api/development/core/health', ['Origin' => 'app://portal'])
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', ApiVersion::VERSION_HEADER.', '.ApiVersion::CHANNEL_HEADER)
            ->assertHeader(ApiVersion::CHANNEL_HEADER, 'development');
    }

    public function test_catalog_urls_include_the_path_prefix(): void
    {
        $this->getJson('/staging/central-api/api/development')
            ->assertOk()
            ->assertJsonFragment(['base_url' => '/staging/central-api/api/development/core']);
    }
}
