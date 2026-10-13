<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More white around the logo on its tile in the built-in footers and the Company signature
 * (2026-10-13, supervisor: the tile is good in dark mode, the logo just sat too close to its edge).
 * c_lpad pads without scaling the logo up, so the white shows around it.
 * Swaps the tight tile's address and size for the roomier one in any footer still carrying it, so a
 * footer rewritten since is left alone. The seeds and sql/portal/separate.sql carry the new tile.
 */
return new class extends Migration
{
    /**
     * Tight => roomy.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(): array
    {
        return [
            ['c_limit,w_144/c_pad,w_172,h_180,b_white/r_26/f_png', 'c_limit,w_136/c_lpad,w_176,h_184,b_white/r_28/f_png'],
            ['width="86" style="display:block;width:86px;max-width:86px;', 'width="88" style="display:block;width:88px;max-width:88px;'],
        ];
    }

    public function up(): void
    {
        $this->swap(self::pairs());
    }

    public function down(): void
    {
        $this->swap(array_map(static fn (array $pair): array => [$pair[1], $pair[0]], self::pairs()));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function swap(array $pairs): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('email_blocks')) {
            return;
        }

        $portal = DB::connection('portal');
        foreach ($portal->table('email_blocks')->where('kind', 'footer')->get(['id', 'body']) as $block) {
            $body = (string) $block->body;
            $next = $body;
            foreach ($pairs as [$from, $to]) {
                $next = str_replace($from, $to, $next);
            }
            if ($next !== $body) {
                $portal->table('email_blocks')->where('id', $block->id)->update(['body' => $next]);
            }
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
