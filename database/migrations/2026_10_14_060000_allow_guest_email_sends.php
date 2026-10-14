<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A guest may send email (2026-10-14, user): the Email page is shareable, and its sends are the one
 * write a guest makes -- they land in All Emails like anyone's. So email_messages.sent_by takes a
 * guest's null (a guest is no employee), and the page joins the shareable list as ready.
 * Also in sql/portal/separate.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if ($schema->hasTable('email_messages') && $schema->hasColumn('email_messages', 'sent_by')) {
            $this->dropSentByForeign();
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->integer('sent_by')->nullable()->change();
                $table->foreign('sent_by', 'email_messages_sent_by_foreign')->references('id')->on('employees')->nullOnDelete();
            });
        }

        if ($schema->hasTable('shared_page_shareable')) {
            DB::connection('portal')->table('shared_page_shareable')->updateOrInsert(
                ['rule_key' => 'email'],
                ['is_ready' => 1],
            );
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if ($schema->hasTable('email_messages') && $schema->hasColumn('email_messages', 'sent_by')) {
            $this->dropSentByForeign();
            $schema->table('email_messages', function (Blueprint $table): void {
                $table->integer('sent_by')->nullable(false)->change();
                $table->foreign('sent_by', 'email_messages_sent_by_foreign')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if ($schema->hasTable('shared_page_shareable')) {
            DB::connection('portal')->table('shared_page_shareable')->where('rule_key', 'email')->update(['is_ready' => 0]);
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

    // A database built from separate.sql names this key itself (email_messages_ibfk_N), so read the
    // real name rather than assume the one Laravel would have given it.
    private function dropSentByForeign(): void
    {
        $foreign = DB::connection('portal')->selectOne(
            'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['email_messages', 'sent_by'],
        );
        if ($foreign !== null) {
            DB::connection('portal')->statement('ALTER TABLE email_messages DROP FOREIGN KEY `'.(string) $foreign->name.'`');
        }
    }
};
