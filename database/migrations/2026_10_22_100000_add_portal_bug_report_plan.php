<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A report's plan (2026-10-22, user): when it starts and is due, the epic it sits under and the team that owns it,
 * each the Jira issue's too once it is linked. The epic's summary and the team's name are kept as Jira last gave
 * them, so the page names them without asking Jira. Mirrored in sql/portal/separate.sql.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{0: string, 1: ?int}>
     */
    private const COLUMNS = [
        'start_on' => ['date', null],
        'due_on' => ['date', null],
        'parent_key' => ['string', 32],
        'parent_summary' => ['string', 255],
        'team_id' => ['string', 64],
        'team_name' => ['string', 255],
    ];

    public function up(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        $after = 'fixed_in';
        foreach (self::COLUMNS as $column => [$type, $length]) {
            if (! Schema::connection('portal')->hasColumn('bug_reports', $column)) {
                Schema::connection('portal')->table('bug_reports', function (Blueprint $table) use ($column, $type, $length, $after): void {
                    ($type === 'date' ? $table->date($column) : $table->string($column, (int) $length))->nullable()->after($after);
                });
            }
            $after = $column;
        }
    }

    public function down(): void
    {
        if (! $this->portalReachable() || ! Schema::connection('portal')->hasTable('bug_reports')) {
            return;
        }
        foreach (array_keys(self::COLUMNS) as $column) {
            if (Schema::connection('portal')->hasColumn('bug_reports', $column)) {
                Schema::connection('portal')->table('bug_reports', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
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
