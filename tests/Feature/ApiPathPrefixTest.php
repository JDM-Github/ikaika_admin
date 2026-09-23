<?php

namespace Tests\Feature;

use App\Support\ApiPath;
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

    public function test_catalog_urls_include_the_path_prefix(): void
    {
        $this->getJson('/staging/central-api/api/development')
            ->assertOk()
            ->assertJsonFragment(['base_url' => '/staging/central-api/api/development/core']);
    }
}
