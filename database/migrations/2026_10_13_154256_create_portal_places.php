<?php

use App\Support\Portal\PortalPlaces;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Philippines' provinces, cities and municipalities, and barangays (2026-10-13, user), for the
 * registration form's address pickers: the PSGC as database/data/psgc keeps it, loaded here so a
 * deploy needs only the migration. `php artisan portal:import-places` loads a newer copy later.
 * Mirrored in sql/portal/separate.sql (the tables; the rows come from the CSVs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        $schema = Schema::connection('portal');

        if (! $schema->hasTable('psgc_provinces')) {
            $schema->create('psgc_provinces', function (Blueprint $table): void {
                // Pinned: the local server defaults new tables to MyISAM, which has no transactions or keys.
                $table->engine = 'InnoDB';
                $table->char('code', 9)->primary();
                $table->string('name', 120);
            });
        }
        if (! $schema->hasTable('psgc_cities')) {
            $schema->create('psgc_cities', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('code', 9)->primary();
                $table->string('name', 120);
                $table->char('province_code', 9)->index();
            });
        }
        if (! $schema->hasTable('psgc_barangays')) {
            $schema->create('psgc_barangays', function (Blueprint $table): void {
                $table->engine = 'InnoDB';
                $table->char('code', 9)->primary();
                $table->string('name', 120);
                $table->char('city_code', 9)->index();
            });
        }

        app(PortalPlaces::class)->import();
    }

    public function down(): void
    {
        if (! $this->portalReachable()) {
            return;
        }
        foreach (['psgc_barangays', 'psgc_cities', 'psgc_provinces'] as $table) {
            Schema::connection('portal')->dropIfExists($table);
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
