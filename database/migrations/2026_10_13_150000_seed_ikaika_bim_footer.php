<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The IKAIKA BIM footer: the Company footer's design for the company writing as itself (Send Email's
 * From: IKAIKA BIM), without IKAIKA PORTAL or "Automated communication". The HTML is
 * database/seeders/email/ikaika-bim-footer.html, inlined in sql/portal/separate.sql for a fresh
 * database; this adds it once where the email tables exist, and leaves a footer of the same name alone.
 */
return new class extends Migration
{
    public const NAME = 'IKAIKA BIM footer';

    // John Dave Pega, who made the built-ins; matched on id_no like is_developer.
    private const CREATOR_ID_NO = '260701-0020';

    public static function body(): string
    {
        return rtrim(str_replace("\r\n", "\n", (string) file_get_contents(database_path('seeders/email/ikaika-bim-footer.html'))));
    }

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        $creator = $portal->table('employees')->where('id_no', self::CREATOR_ID_NO)->value('id');
        if ($creator === null || $portal->table('email_blocks')->where('kind', 'footer')->where('name', self::NAME)->exists()) {
            return;
        }

        $portal->table('email_blocks')->insert([
            'kind' => 'footer',
            'name' => self::NAME,
            'category' => null,
            'subject' => null,
            'body' => self::body(),
            'format' => 'html',
            'created_by' => (int) $creator,
        ]);
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        DB::connection('portal')->table('email_blocks')
            ->where('kind', 'footer')->where('name', self::NAME)->where('body', self::body())
            ->delete();
    }

    private function portalReachable(): bool
    {
        try {
            Schema::connection('portal')->getConnection()->getPdo();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
};
