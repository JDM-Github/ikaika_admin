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

        $this->assertStringContainsString('/c_limit,w_112,f_auto,q_auto/', $footer);
        $this->assertStringContainsString('/email-upload/global/', $footer);
        $this->assertStringContainsString('width="82"', $footer);
        $this->assertDoesNotMatchRegularExpression('#email-upload/\d+/#', $footer.$this->migration()::body('portal-email-test.html'));
    }
}
