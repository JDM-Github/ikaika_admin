<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email blocks and the outbox behind Administration / Send Email. Schema also lives in
 * sql/portal/separate.sql. Fresh local installs get the tables from that dump; this
 * creates them when the portal database already exists and they are still missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        if (! Schema::connection('portal')->hasTable('email_blocks')) {
            Schema::connection('portal')->create('email_blocks', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('kind', 16);
                $table->string('name', 191);
                $table->string('category', 32)->nullable();
                $table->string('subject', 255)->nullable();
                $table->text('body');
                $table->integer('created_by');
                $table->dateTime('date_created')->useCurrent();
                $table->dateTime('date_updated')->nullable();
                $table->index(['kind', 'name'], 'idx_email_blocks_kind');
                $table->foreign('created_by')->references('id')->on('employees')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('portal')->hasTable('email_messages')) {
            Schema::connection('portal')->create('email_messages', function (Blueprint $table): void {
                $table->integer('id')->autoIncrement();
                $table->string('category', 32);
                $table->string('subject', 255);
                $table->text('body');
                $table->integer('footer_id')->nullable();
                $table->text('footer_body')->nullable();
                $table->string('audience', 32);
                $table->json('audience_filter')->nullable();
                $table->integer('recipient_count')->default(0);
                $table->integer('sent_count')->default(0);
                $table->integer('failed_count')->default(0);
                $table->json('failures')->nullable();
                $table->string('status', 16)->default('sent');
                $table->integer('sent_by');
                $table->dateTime('date_sent')->nullable();
                $table->dateTime('date_created')->useCurrent();
                $table->index('date_created', 'idx_email_messages_created');
                $table->index('sent_by', 'idx_email_messages_sent_by');
                $table->foreign('footer_id')->references('id')->on('email_blocks')->nullOnDelete();
                $table->foreign('sent_by')->references('id')->on('employees')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('email_messages');
        Schema::connection('portal')->dropIfExists('email_blocks');
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
