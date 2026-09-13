<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('logs')) {
            return;
        }

        if (! $schema->hasColumn('logs', 'location_label')) {
            $schema->table('logs', function (Blueprint $table): void {
                $table->string('location_label', 255)->nullable();
            });
        }

        if (! $schema->hasColumn('logs', 'location_source')) {
            $schema->table('logs', function (Blueprint $table): void {
                $table->string('location_source', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $schema = Schema::connection('portal');
        if (! $schema->hasTable('logs')) {
            return;
        }

        $columns = array_values(array_filter([
            $schema->hasColumn('logs', 'location_label') ? 'location_label' : null,
            $schema->hasColumn('logs', 'location_source') ? 'location_source' : null,
        ]));
        if ($columns === []) {
            return;
        }

        $schema->table('logs', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
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
