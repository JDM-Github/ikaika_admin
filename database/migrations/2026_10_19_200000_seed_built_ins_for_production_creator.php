<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The built-in email blocks and John Dave Pega's developer flag were matched on his test id_no
 * (260701-0020), and production's Airtable records him as 260720-0020, so on production every built-in
 * seed matched nobody: no Company footer, no Company signature (the signature design), no IKAIKA BIM footer.
 * This adds whichever block is missing, from the same database/seeders/email files, once by kind and name.
 */
return new class extends Migration
{
    // John Dave Pega, who made the built-ins: the test data's id_no, then production's.
    public const CREATOR_ID_NOS = ['260701-0020', '260720-0020'];

    /**
     * @return list<array{kind: string, name: string, category: ?string, subject: ?string, file: string}>
     */
    public static function blocks(): array
    {
        return [
            ['kind' => 'footer', 'name' => 'Company footer', 'category' => null, 'subject' => null, 'file' => 'company-footer.html'],
            ['kind' => 'footer', 'name' => 'Company signature', 'category' => null, 'subject' => null, 'file' => 'company-signature.html'],
            ['kind' => 'footer', 'name' => 'IKAIKA BIM footer', 'category' => null, 'subject' => null, 'file' => 'ikaika-bim-footer.html'],
            ['kind' => 'template', 'name' => 'Portal email test', 'category' => 'notice', 'subject' => 'Portal email test', 'file' => 'portal-email-test.html'],
        ];
    }

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        $creator = $portal->table('employees')->whereIn('id_no', self::CREATOR_ID_NOS)->orderBy('id')->value('id');
        if ($creator === null) {
            return;
        }
        if (Schema::connection('portal')->hasColumn('employees', 'is_developer')) {
            $portal->table('employees')->where('id', $creator)->update(['is_developer' => 1]);
        }

        foreach (self::blocks() as $block) {
            if ($portal->table('email_blocks')->where('kind', $block['kind'])->where('name', $block['name'])->exists()) {
                continue;
            }
            $portal->table('email_blocks')->insert([
                'kind' => $block['kind'],
                'name' => $block['name'],
                'category' => $block['category'],
                'subject' => $block['subject'],
                'body' => rtrim((string) file_get_contents(database_path('seeders/email/'.$block['file']))),
                'format' => 'html',
                'created_by' => (int) $creator,
            ]);
        }
    }

    // Leaves the blocks: by now they may be in use as signatures and on sent mail.
    public function down(): void {}

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
