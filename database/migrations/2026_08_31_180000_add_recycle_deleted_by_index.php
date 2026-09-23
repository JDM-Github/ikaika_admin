<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Own-bin and All Recycled filter by deleted_by on core.recycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->coreReachable() || ! Schema::connection('core')->hasTable('recycle')) {
            return;
        }

        if ($this->indexExists('idx_recycle_product_deleted_by')) {
            return;
        }

        Schema::connection('core')->table('recycle', function (Blueprint $table): void {
            $table->index(['product', 'deleted_by'], 'idx_recycle_product_deleted_by');
        });
    }

    public function down(): void
    {
        if (! $this->coreReachable() || ! Schema::connection('core')->hasTable('recycle')) {
            return;
        }

        if (! $this->indexExists('idx_recycle_product_deleted_by')) {
            return;
        }

        Schema::connection('core')->table('recycle', function (Blueprint $table): void {
            $table->dropIndex('idx_recycle_product_deleted_by');
        });
    }

    private function coreReachable(): bool
    {
        try {
            Schema::connection('core')->getConnection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function indexExists(string $index): bool
    {
        try {
            $db = Schema::connection('core')->getConnection();
            $row = $db->selectOne(
                'select 1 as present from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                [$db->getDatabaseName(), 'recycle', $index],
            );

            return $row !== null;
        } catch (Throwable) {
            return false;
        }
    }
};
