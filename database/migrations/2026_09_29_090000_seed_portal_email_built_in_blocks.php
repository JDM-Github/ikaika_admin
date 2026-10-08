<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The composer's built-in blocks: the company footer and the Portal email test template, both HTML
 * from database/seeders/email/. sql/portal/separate.sql inlines the same HTML for a fresh database;
 * this adds them where the email tables already exist, once, and leaves a block of the same name alone.
 */
return new class extends Migration
{
    // John Dave Pega, who made them; matched on id_no like is_developer.
    private const CREATOR_ID_NO = '260701-0020';

    /**
     * @return list<array{kind: string, name: string, category: ?string, subject: ?string, file: string}>
     */
    public static function blocks(): array
    {
        return [
            ['kind' => 'footer', 'name' => 'Company footer', 'category' => null, 'subject' => null, 'file' => 'company-footer.html'],
            ['kind' => 'template', 'name' => 'Portal email test', 'category' => 'notice', 'subject' => 'Portal email test', 'file' => 'portal-email-test.html'],
        ];
    }

    public static function body(string $file): string
    {
        return rtrim((string) file_get_contents(database_path('seeders/email/'.$file)));
    }

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        $creator = $portal->table('employees')->where('id_no', self::CREATOR_ID_NO)->value('id');
        if ($creator === null) {
            return;
        }

        foreach (self::blocks() as $block) {
            $exists = $portal->table('email_blocks')->where('kind', $block['kind'])->where('name', $block['name'])->exists();
            if ($exists) {
                continue;
            }
            $portal->table('email_blocks')->insert([
                'kind' => $block['kind'],
                'name' => $block['name'],
                'category' => $block['category'],
                'subject' => $block['subject'],
                'body' => self::body($block['file']),
                'format' => 'html',
                'created_by' => (int) $creator,
            ]);
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        foreach (self::blocks() as $block) {
            DB::connection('portal')->table('email_blocks')
                ->where('kind', $block['kind'])->where('name', $block['name'])->where('body', self::body($block['file']))
                ->delete();
        }
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
