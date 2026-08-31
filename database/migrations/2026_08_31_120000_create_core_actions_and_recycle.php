<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core actions / recycle / settings. Schema also lives in sql/core/schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->coreReachable()) {
            return;
        }

        if (! Schema::connection('core')->hasTable('settings')) {
            Schema::connection('core')->create('settings', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('product', 64);
                $table->string('setting_key', 100);
                $table->text('setting_value');
                $table->unique(['product', 'setting_key'], 'uniq_settings_product_key');
            });
        }

        if (! Schema::connection('core')->hasTable('actions')) {
            Schema::connection('core')->create('actions', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('product', 64);
                $table->string('database_target', 191);
                $table->string('action_type', 32);
                $table->string('resource', 191)->nullable();
                $table->string('record_id', 191)->nullable();
                $table->string('recycle_key', 191)->nullable();
                $table->integer('actor_id')->nullable();
                $table->string('actor_id_no', 100)->nullable();
                $table->json('parameters');
                $table->timestamp('synced_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['product', 'created_at'], 'idx_actions_product_created');
                $table->index(['product', 'action_type'], 'idx_actions_product_type');
                $table->index(['product', 'recycle_key'], 'idx_actions_product_key');
                $table->index(['synced_at', 'created_at'], 'idx_actions_unsynced');
            });
        }

        if (! Schema::connection('core')->hasTable('recycle')) {
            Schema::connection('core')->create('recycle', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('product', 64);
                $table->string('recycle_key', 191);
                $table->string('database_target', 191);
                $table->string('resource', 191)->nullable();
                $table->string('record_id', 191);
                $table->json('payload');
                $table->integer('deleted_by')->nullable();
                $table->string('deleted_by_id_no', 100)->nullable();
                $table->timestamp('purges_at');
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['product', 'recycle_key'], 'uniq_recycle_product_key');
                $table->index(['product', 'purges_at'], 'idx_recycle_product_purge');
            });
        }

        $this->seedRetainDays();
    }

    public function down(): void
    {
        if (! $this->coreReachable()) {
            return;
        }

        Schema::connection('core')->dropIfExists('recycle');
        Schema::connection('core')->dropIfExists('actions');
        Schema::connection('core')->dropIfExists('settings');
    }

    private function seedRetainDays(): void
    {
        if (Schema::connection('core')->hasTable('settings') === false) {
            return;
        }

        foreach (['portal', 'project-estimator'] as $product) {
            $exists = DB::connection('core')
                ->table('settings')
                ->where('product', $product)
                ->where('setting_key', 'recycle.retain_days')
                ->exists();
            if ($exists) {
                continue;
            }

            DB::connection('core')->table('settings')->insert([
                'product' => $product,
                'setting_key' => 'recycle.retain_days',
                'setting_value' => '30',
            ]);
        }
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
