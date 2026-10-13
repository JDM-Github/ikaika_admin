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

        // The logo at 72px on a white tile (86px with its margin), fetched at twice that for sharp screens:
        // the tile is unseen on white and keeps the navy logo readable where a mail client darkens the page.
        foreach (['company-footer.html', 'ikaika-bim-footer.html', 'company-signature.html'] as $file) {
            $body = $this->migration()::body($file);
            // c_lpad pads without scaling the logo up, so white shows all round it.
            $this->assertStringContainsString('/c_limit,w_136/c_lpad,w_176,h_184,b_white/r_28/f_png/', $body, $file);
            $this->assertStringContainsString('width="88"', $body, $file);
        }
        $this->assertStringContainsString('/email-upload/global/', $footer);
        $this->assertDoesNotMatchRegularExpression('#email-upload/\d+/#', $footer.$this->migration()::body('portal-email-test.html'));
        // The icons are pictures from email-design/, never SVG, which mail clients drop.
        foreach (['icon-globe', 'icon-mail', 'icon-pin'] as $icon) {
            $this->assertStringContainsString('/email-design/'.$icon.'.png', $footer);
        }
        $this->assertStringNotContainsString('<svg', $footer);
    }

    // The company's own footer: the same design without the portal's lines, added once, the same from SQL.
    public function test_the_ikaika_bim_footer_is_added_once_and_the_same_from_sql(): void
    {
        $bim = require database_path('migrations/2026_10_13_150000_seed_ikaika_bim_footer.php');
        $body = $bim::body();
        $this->assertStringContainsString('>IKAIKA BIM</div>', $body);
        $this->assertStringNotContainsString('IKAIKA PORTAL', $body);
        $this->assertStringNotContainsString('Automated communication', $body);

        $portal = DB::connection('portal');
        $stored = fn () => $portal->table('email_blocks')->where('kind', 'footer')->where('name', $bim::NAME)->get(['body', 'format']);
        $portal->table('email_blocks')->where('kind', 'footer')->where('name', $bim::NAME)->delete();

        $bim->up();
        $bim->up();
        $this->assertCount(1, $stored());
        $this->assertSame($body, rtrim(str_replace("\r\n", "\n", (string) $stored()[0]->body)));
        $this->assertSame('html', $stored()[0]->format);

        $portal->table('email_blocks')->where('kind', 'footer')->where('name', $bim::NAME)->delete();
        $sql = str_replace("\r\n", "\n", (string) file_get_contents(base_path('sql/portal/separate.sql')));
        $this->assertSame(1, preg_match('/-- The IKAIKA BIM footer.*?\n\n/s', $sql, $section));
        $portal->unprepared($section[0]);
        $this->assertCount(1, $stored());
        $this->assertSame($body, rtrim(str_replace("\r\n", "\n", (string) $stored()[0]->body)));
    }

    // The dark-mode versions replace only footers still exactly the previous built-ins.
    public function test_the_dark_mode_migration_replaces_only_unedited_footers(): void
    {
        $darkSafe = require database_path('migrations/2026_10_13_170000_dark_mode_safe_footers.php');
        $bim = require database_path('migrations/2026_10_13_150000_seed_ikaika_bim_footer.php');
        $this->assertSame($bim::body(), $this->roomier($darkSafe::BIM_NEW));

        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $company = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old company', 'body' => $darkSafe::COMPANY_OLD, 'format' => 'html', 'created_by' => $creator]);
        $bimRow = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old BIM', 'body' => str_replace("\n", "\r\n", $darkSafe::BIM_OLD), 'format' => 'html', 'created_by' => $creator]);
        $own = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Own', 'body' => '<p>Ours</p>', 'format' => 'html', 'created_by' => $creator]);

        $darkSafe->up();

        $this->assertSame($darkSafe::COMPANY_NEW, $portal->table('email_blocks')->where('id', $company)->value('body'));
        $this->assertSame($darkSafe::BIM_NEW, $portal->table('email_blocks')->where('id', $bimRow)->value('body'));
        $this->assertSame('<p>Ours</p>', $portal->table('email_blocks')->where('id', $own)->value('body'));
    }

    // The Company signature takes the footers' design, never new words: every placeholder and sentence stays.
    public function test_the_signature_keeps_its_words_in_the_new_design_and_fits_an_exchange_rule(): void
    {
        $restyle = require database_path('migrations/2026_10_13_180000_restyle_company_signature.php');
        $this->assertSame($this->migration()::body('company-signature.html'), $this->roomier($restyle::NEW));

        // The words and placeholders in order; separators and line breaks are layout, not wording.
        $words = static function (string $html): array {
            preg_match_all('/[{}\w@.\'-]+/u', html_entity_decode(strip_tags($html)), $match);

            return $match[0];
        };
        $this->assertSame($words($restyle::OLD), $words($restyle::NEW));
        foreach (['{name}', '{title}', '{department}', '{email}', '{phone}', '{location}'] as $token) {
            $this->assertStringContainsString($token, $restyle::NEW);
        }
        // Filled for a long name it still fits the 5000 characters an Exchange disclaimer rule allows.
        $filled = str_replace(['{name}', '{title}', '{department}', '{email}', '{phone}', '{location}'], [str_repeat('N', 60), str_repeat('T', 60), str_repeat('D', 40), str_repeat('e', 50).'@ikaikabim.com', '0917 123 4567', str_repeat('L', 40)], $restyle::NEW);
        $this->assertLessThan(5000, strlen($filled));

        // Same in a fresh database, and only an unedited signature is replaced.
        $sql = str_replace("\r\n", "\n", (string) file_get_contents(base_path('sql/portal/separate.sql')));
        $this->assertStringContainsString("'Company signature', NULL, NULL, '".str_replace("'", "''", $this->roomier($restyle::NEW))."', 'html'", $sql);
        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $stale = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old signature', 'body' => $restyle::OLD, 'format' => 'html', 'created_by' => $creator]);
        $own = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Own signature', 'body' => '<p>{name}</p>', 'format' => 'html', 'created_by' => $creator]);
        $restyle->up();
        $this->assertSame($restyle::NEW, $portal->table('email_blocks')->where('id', $stale)->value('body'));
        $this->assertSame('<p>{name}</p>', $portal->table('email_blocks')->where('id', $own)->value('body'));
    }

    // The roomier tile replaces the tight one wherever a footer still carries it, edited or not.
    public function test_the_roomier_tile_migration_gives_the_logo_room_in_every_footer(): void
    {
        $roomy = require database_path('migrations/2026_10_13_190000_roomier_logo_tile.php');
        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $tight = '<img src="https://res.cloudinary.com/x/image/upload/c_limit,w_144/c_pad,w_172,h_180,b_white/r_26/f_png/v1/l.png" width="86" style="display:block;width:86px;max-width:86px;height:auto;" /><p>Own words</p>';
        $id = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Edited', 'body' => $tight, 'format' => 'html', 'created_by' => $creator]);

        $roomy->up();
        $this->assertSame($this->roomier($tight), $portal->table('email_blocks')->where('id', $id)->value('body'));
        $this->assertStringContainsString('<p>Own words</p>', (string) $portal->table('email_blocks')->where('id', $id)->value('body'));

        $roomy->down();
        $this->assertSame($tight, $portal->table('email_blocks')->where('id', $id)->value('body'));
    }

    // A footer as the roomier-tile migration leaves it.
    private function roomier(string $html): string
    {
        foreach ((require database_path('migrations/2026_10_13_190000_roomier_logo_tile.php'))::pairs() as [$from, $to]) {
            $html = str_replace($from, $to, $html);
        }

        return $html;
    }

    // A footer still the previous built-in becomes the new design; one someone rewrote is left alone.
    public function test_the_redesign_migration_replaces_only_an_unedited_footer(): void
    {
        $redesign = require database_path('migrations/2026_10_13_120000_redesign_company_footer.php');
        // Each later version starts where this one ended, and the last is the seed.
        $darkSafe = require database_path('migrations/2026_10_13_170000_dark_mode_safe_footers.php');
        $this->assertSame($redesign::NEW, $darkSafe::COMPANY_OLD);
        $this->assertSame($this->migration()::body('company-footer.html'), $this->roomier($darkSafe::COMPANY_NEW));

        $portal = DB::connection('portal');
        $creator = (int) $portal->table('employees')->value('id');
        $stale = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Old built-in', 'body' => str_replace("\n", "\r\n", $redesign::OLD), 'format' => 'html', 'created_by' => $creator]);
        $own = $portal->table('email_blocks')->insertGetId(['kind' => 'footer', 'name' => 'Own footer', 'body' => '<p>Ours</p>', 'format' => 'html', 'created_by' => $creator]);

        $redesign->up();

        $this->assertSame($redesign::NEW, $portal->table('email_blocks')->where('id', $stale)->value('body'));
        $this->assertSame('<p>Ours</p>', $portal->table('email_blocks')->where('id', $own)->value('body'));
    }
}
