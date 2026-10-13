<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Company footer's office address becomes a link of its own (to the map), styled as plain text,
 * because mail clients found the bare address and underlined it in blue. database/seeders/email and
 * sql/portal/separate.sql carry the new lines; this swaps them into a footer that still has the old
 * ones, so a footer someone rewrote is left as it is.
 */
return new class extends Migration
{
    public const OLD = <<<'HTML'
      <!-- Location -->
      <div style="margin-top:3px;font-size:11px;line-height:16px;color:#64748b;">
        11th Floor, SM Aura Office Tower &nbsp;&middot;&nbsp; 26th St. corner McKinley Parkway
      </div>
      <div style="font-size:11px;line-height:16px;color:#64748b;">
        Fort Bonifacio, Taguig City, Metro Manila, Philippines
      </div>
HTML;

    public const NEW = <<<'HTML'
      <!-- Location: a link of its own, so a mail client does not underline it as an address it found -->
      <div style="margin-top:3px;font-size:11px;line-height:16px;color:#64748b;">
        <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#64748b;text-decoration:none;">11th Floor, SM Aura Office Tower &nbsp;&middot;&nbsp; 26th St. corner McKinley Parkway</a>
      </div>
      <div style="font-size:11px;line-height:16px;color:#64748b;">
        <a href="https://www.google.com/maps/search/?api=1&amp;query=SM+Aura+Office+Tower+Taguig" style="color:#64748b;text-decoration:none;">Fort Bonifacio, Taguig City, Metro Manila, Philippines</a>
      </div>
HTML;

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        foreach ($portal->table('email_blocks')->where('kind', 'footer')->get(['id', 'body']) as $block) {
            // A body saved from Windows keeps CRLF; the lines are matched either way.
            $body = str_replace("\r\n", "\n", (string) $block->body);
            if (substr_count($body, $from) !== 1) {
                continue;
            }
            $portal->table('email_blocks')->where('id', $block->id)->update(['body' => str_replace($from, $to, $body)]);
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
