<?php

namespace Tests\Unit;

use App\Support\Portal\PortalJiraEnvironment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// One Jira project for production and staging (2026-10-21, user): which server files what, and whose reports it follows.
class PortalJiraEnvironmentTest extends TestCase
{
    public function test_production_files_as_it_always_has_and_follows_the_reports_from_before(): void
    {
        config(['products.channel' => 'production']);
        $environment = new PortalJiraEnvironment;

        $this->assertTrue($environment->isProduction());
        $this->assertNull($environment->label());
        $this->assertSame(['ikaika-portal'], $environment->labels());
        $this->assertTrue($environment->ownsRow((object) ['channel' => null]));
        $this->assertTrue($environment->ownsRow((object) ['channel' => 'production']));
        $this->assertFalse($environment->ownsRow((object) ['channel' => 'staging']));
        $this->assertSame('labels = "ikaika-portal"', $environment->searchJql());
        $this->assertSame('project = IPS AND labels = ikaika-portal', $environment->webhookJql('IPS'));
    }

    public function test_staging_adds_its_label_and_follows_only_what_was_sent_on_it(): void
    {
        config(['products.channel' => ' Staging ']);
        $environment = new PortalJiraEnvironment;

        $this->assertSame('staging', $environment->channel());
        $this->assertSame('staging', $environment->label());
        $this->assertSame(['ikaika-portal', 'staging'], $environment->labels());
        $this->assertFalse($environment->ownsRow((object) ['channel' => null]));
        $this->assertFalse($environment->ownsRow((object) ['channel' => 'production']));
        $this->assertTrue($environment->ownsRow((object) ['channel' => 'STAGING']));
        $this->assertSame('labels = "ikaika-portal" AND labels = "staging"', $environment->searchJql());
        $this->assertSame('project = IPS AND labels = ikaika-portal AND labels = staging', $environment->webhookJql('IPS'));

        config(['products.channel' => '']);
        $this->assertSame('development', $environment->channel());
    }

    public function test_each_servers_query_keeps_only_its_own_reports(): void
    {
        config(['products.channel' => 'production']);
        $production = (new PortalJiraEnvironment)->scope(DB::connection('portal')->table('bug_reports'));
        $this->assertStringContainsString('(`channel` is null or `channel` = ?)', $production->toSql());
        $this->assertSame(['production'], $production->getBindings());

        config(['products.channel' => 'staging']);
        $staging = (new PortalJiraEnvironment)->scope(DB::connection('portal')->table('bug_reports'));
        $this->assertStringContainsString('where `channel` = ?', $staging->toSql());
        $this->assertSame(['staging'], $staging->getBindings());
    }
}
