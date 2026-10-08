<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Send Email's attachments. A row is a file on the private disk: staged (message_id null) from the
 * moment it is attached until a send claims it, then kept with that outbox row so All Emails can
 * list it and hand it back. token is the id the browser holds, so rows are never numbered at it.
 * Schema also lives in sql/portal/separate.sql; this creates the table where the portal database
 * already exists.
 *
 * The engine is named, not left to the server: a send claims its files under a row lock inside the
 * transaction that opens the outbox row, and the keys tie a file to its email and its uploader. A
 * server that defaults to MyISAM would quietly drop all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable() || Schema::connection('portal')->hasTable('email_attachments')) {
            return;
        }

        Schema::connection('portal')->create('email_attachments', function (Blueprint $table): void {
            $table->engine('InnoDB');
            $table->integer('id')->autoIncrement();
            $table->char('token', 36)->unique('uq_email_attachments_token');
            $table->integer('message_id')->nullable();
            $table->string('name', 191);
            $table->string('mime', 127);
            $table->integer('size_bytes');
            $table->string('path', 255);
            $table->integer('uploaded_by');
            $table->dateTime('date_created')->useCurrent();
            $table->index('message_id', 'idx_email_attachments_message');
            $table->index(['uploaded_by', 'message_id'], 'idx_email_attachments_staged');
            $table->foreign('message_id')->references('id')->on('email_messages')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        Schema::connection('portal')->dropIfExists('email_attachments');
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
