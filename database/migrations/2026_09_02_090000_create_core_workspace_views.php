<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved workspace views. Core, not the product database, because a view can name a
 * table in any product and the product databases are mirrors we do not add to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->coreReachable() || Schema::connection('core')->hasTable('workspace_views')) {
            return;
        }

        Schema::connection('core')->create('workspace_views', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('product', 64);
            $table->string('table_name', 191);
            $table->string('name', 100);

            // The grid's whole state is its query string, so a view is one.
            $table->text('query');
            $table->integer('owner_id');
            $table->string('owner_id_no', 100)->nullable();
            $table->boolean('shared')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['owner_id', 'product', 'table_name', 'name'], 'uniq_views_owner_table_name');
            $table->index(['product', 'table_name'], 'idx_views_product_table');
            $table->index(['shared', 'product', 'table_name'], 'idx_views_shared_table');
        });
    }

    public function down(): void
    {
        if (! $this->coreReachable()) {
            return;
        }

        Schema::connection('core')->dropIfExists('workspace_views');
    }

    private function coreReachable(): bool
    {
        try {
            Schema::connection('core')->getConnection()->getPdo();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
};
