<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The composer's built-in blocks -- the company footer and the Portal email test template -- come from
 * database/seeders/email/, through a migration on an existing database and through separate.sql on a
 * fresh one. What is pinned here is that both store exactly those files, once, and that the footer's
 * logo is the small one in Cloudinary's global folder, never a person's own image.
 */
class PortalEmailBuiltInsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private function migration(): object
    {
        return require database_path('migrations/2026_09_29_090000_seed_portal_email_built_in_blocks.php');
    }

    private function forgetBuiltIns(): void
    {
        foreach ($this->migration()::blocks() as $block) {
            DB::connection('portal')->table('email_blocks')->where('kind', $block['kind'])->where('name', $block['name'])->delete();
        }
    }

    private function assertBuiltInsStored(): void
    {
        $migration = $this->migration();
        $creator = DB::connection('portal')->table('employees')->where('id_no', '260701-0020')->value('id');
        foreach ($migration::blocks() as $block) {
            $rows = DB::connection('portal')->table('email_blocks')->where('kind', $block['kind'])->where('name', $block['name'])->get();
            $this->assertCount(1, $rows, $block['name']);
            $this->assertSame($migration::body($block['file']), rtrim(str_replace("\r\n", "\n", (string) $rows[0]->body)));
            $this->assertSame('html', $rows[0]->format);
            $this->assertSame($block['subject'], $rows[0]->subject);
            $this->assertSame((int) $creator, (int) $rows[0]->created_by);
        }
    }

    public function test_the_migration_adds_each_built_in_once(): void
    {
        $this->forgetBuiltIns();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertBuiltInsStored();
    }

    public function test_separate_sql_seeds_the_same_html(): void
    {
        $sql = str_replace("\r\n", "\n", (string) file_get_contents(base_path('sql/portal/separate.sql')));
        $this->assertSame(1, preg_match('/-- Built-in blocks.*?\n\n/s', $sql, $section));
        $this->forgetBuiltIns();

        DB::connection('portal')->unprepared($section[0]);

        $this->assertBuiltInsStored();
    }

    public function test_the_footer_uses_the_small_global_logo(): void
    {
        $footer = $this->migration()::body('company-footer.html');

        // The logo at 72px, fetched at twice that for sharp screens.
        $this->assertStringContainsString('/c_limit,w_144,f_auto,q_auto/', $footer);
        $this->assertStringContainsString('/email-upload/global/', $footer);
        $this->assertStringContainsString('width="72"', $footer);
        $this->assertDoesNotMatchRegularExpression('#email-upload/\d+/#', $footer.$this->migration()::body('portal-email-test.html'));
        // The icons are pictures from email-design/, never SVG, which mail clients drop.
        foreach (['icon-globe', 'icon-mail', 'icon-pin'] as $icon) {
            $this->assertStringContainsString('/email-design/'.$icon.'.png', $footer);
        }
        $this->assertStringNotContainsString('<svg', $footer);
    }

    // A footer still the previous built-in becomes the new design; one someone rewrote is left alone.
    public function test_the_redesign_migration_replaces_only_an_unedited_footer(): void
    {
        $redesign = require database_path('migrations/2026_10_13_120000_redesign_company_footer.php');
        $this->assertSame($this->migration()::body('company-footer.html'), $redesign::NEW);

        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $stale = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old built-in', 'body' => str_replace("\n", "\r\n", $redesign::OLD), 'format' => 'html', 'created_by' => $creator]);
        $own = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Own footer', 'body' => '<p>Ours</p>', 'format' => 'html', 'created_by' => $creator]);

        $redesign->up();

        $this->assertSame($redesign::NEW, $portal->table('email_blocks')->where('id', $stale)->value('body'));
        $this->assertSame('<p>Ours</p>', $portal->table('email_blocks')->where('id', $own)->value('body'));
    }
}
