<?php

namespace Tests\Feature;

use App\Support\ApiVersion;
use Tests\TestCase;

/**
 * The reported ask (2026-10-21): every answer names the backend that gave it - its release and its channel - so the
 * portal's Settings can say which server it is talking to, including a page on another origin such as the desktop app.
 */
class ApiVersionHeadersTest extends TestCase
{
    public function test_health_names_the_release_and_the_channel(): void
    {
        $version = ApiVersion::fromFile(base_path('VERSION'));
        $this->assertNotNull($version, 'The repository VERSION should read as x.y.z+n.');

        $this->getJson('/api/development/core/health')
            ->assertOk()
            ->assertHeader(ApiVersion::VERSION_HEADER, $version)
            ->assertHeader(ApiVersion::CHANNEL_HEADER, 'development')
            ->assertJsonPath('version', $version);
    }

    public function test_refusals_and_unknown_addresses_carry_them_too(): void
    {
        $this->getJson('/api/development/portal/bug-reports')
            ->assertUnauthorized()
            ->assertHeader(ApiVersion::VERSION_HEADER)
            ->assertHeader(ApiVersion::CHANNEL_HEADER, 'development');

        // A channel this server does not serve matches no route at all.
        $this->getJson('/api/no-such-channel/core/health')
            ->assertNotFound()
            ->assertHeader(ApiVersion::VERSION_HEADER)
            ->assertHeader(ApiVersion::CHANNEL_HEADER, 'development');
    }

    public function test_another_origin_may_read_them(): void
    {
        $this->getJson('/api/development/core/health', ['Origin' => 'app://portal'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Expose-Headers', ApiVersion::VERSION_HEADER.', '.ApiVersion::CHANNEL_HEADER);
    }

    public function test_without_a_readable_version_only_the_channel_goes(): void
    {
        config(['products.version' => null]);

        $this->getJson('/api/development/core/health')
            ->assertOk()
            ->assertHeaderMissing(ApiVersion::VERSION_HEADER)
            ->assertHeader(ApiVersion::CHANNEL_HEADER, 'development')
            ->assertJsonPath('version', null);
    }

    public function test_the_version_is_the_first_line_that_is_neither_blank_nor_a_comment(): void
    {
        $this->assertSame('1.0.0+2', ApiVersion::fromText("1.0.0+2\n"));
        $this->assertSame('1.2.3', ApiVersion::fromText("# the version\n\n  1.2.3  \r\n"));
        $this->assertSame('1.0.0+2', ApiVersion::fromText("\xEF\xBB\xBF1.0.0+2"));
        $this->assertNull(ApiVersion::fromText(''));
        $this->assertNull(ApiVersion::fromText("# only a comment\n"));
        $this->assertNull(ApiVersion::fromText("v1.0.0+2\n"));
        $this->assertNull(ApiVersion::fromText("1.0.0+2-released\n"));
        $this->assertNull(ApiVersion::fromText("not a version\n1.0.0\n"));
        $this->assertNull(ApiVersion::fromFile(base_path('no-such-VERSION')));
    }
}
