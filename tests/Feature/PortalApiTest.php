<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Support\ApiPath;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    public function test_playground_loads(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_playground_api_paths_include_the_subdirectory_the_page_is_served_from(): void
    {
        URL::useOrigin('https://ikaikabim.com/staging/central-api');

        try {
            // The same bootstrap the route passes: assembling it here by hand let this
            // test pass while the page itself was missing a variable.
            $html = view('playground', ApiPath::pageBootstrap('staging'))->render();

            $this->assertStringContainsString('/staging/central-api/api', $html);
            $this->assertStringContainsString('/staging/central-api/api/staging', $html);
        } finally {
            URL::useOrigin(null);
        }
    }

    public function test_catalog_lists_portal_on_the_development_channel(): void
    {
        $this->getJson('/api/development')
            ->assertOk()
            ->assertJsonPath('channel', 'development')
            ->assertJsonPath('convention', '/api/{channel}/{product}/{resource}')
            ->assertJsonFragment(['key' => 'portal', 'database' => 'test_portal_database'])
            ->assertJsonFragment(['base_url' => '/api/development/portal']);
    }

    public function test_catalog_exposes_portal_auth_and_separated_manage_users(): void
    {
        $portal = collect($this->getJson('/api/development')->json('products'))
            ->firstWhere('key', 'portal');

        $this->assertIsArray($portal);
        $this->assertSame('Login', $portal['auth'][0]['label'] ?? null);
        $this->assertSame('POST', $portal['auth'][0]['method'] ?? null);
        $this->assertSame('/api/development/portal/auth/login', $portal['auth'][0]['url'] ?? null);
        $this->assertSame('Microsoft login', $portal['auth'][1]['label'] ?? null);
        $this->assertSame('POST', $portal['auth'][1]['method'] ?? null);
        $this->assertSame('/api/development/portal/auth/login/microsoft', $portal['auth'][1]['url'] ?? null);
        $manage = collect($portal['sections'])->firstWhere('name', 'manage');
        $this->assertIsArray($manage);
        $this->assertSame('Users', $manage['resources'][0]['label'] ?? null);
        $this->assertSame('PATCH', $manage['resources'][0]['operations'][1]['method'] ?? null);
        $this->assertNotEmpty($portal['resources']);
    }

    public function test_playground_has_login_and_product_navigation(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Sign in', false)
            ->assertSee('id_no', false)
            ->assertSee('Separated', false)
            ->assertSee('Practice', false)
            ->assertSee('data-product', false);
    }

    public function test_playground_offers_a_placeholder_sync_to_airtable_control(): void
    {
        // The button is real markup, hidden until the actions table is open (see
        // resources/views/playground/_script.blade.php) -- present-but-hidden is what a
        // static render can assert; the client-side show/hide toggle has no PHP seam to test.
        $this->get('/')
            ->assertOk()
            ->assertSee('id="sync-airtable"', false)
            ->assertSee('Sync to Airtable', false);
    }

    public function test_portal_module_reads_the_portal_database(): void
    {
        $this->getJson('/api/development/portal')
            ->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('health.database', 'test_portal_database');
    }

    public function test_portal_employees_endpoint(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/employees', [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertOk()
            ->assertJsonPath('product', 'portal')
            ->assertJsonPath('resource', 'employees')
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page']]);
    }

    public function test_disabled_estimator_is_not_served(): void
    {
        // Pinned rather than inherited: a local .env that turns the placeholder on must not
        // change what this asserts about a product that is off.
        config(['products.catalog.project-estimator.enabled' => false]);

        $this->getJson('/api/development/project-estimator')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Product [project-estimator] is registered but not enabled.');
    }
}
