<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * leave_types and teams gained a code and a description, so a type or office is identified by a short
 * code rather than by numbering inside its name: "01 Vacation Leave" became code 01, name
 * "Vacation Leave". Existing rows are split in place, and existing reimbursements.team values get the
 * office code prefixed so a stored value still matches the list the form offers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->portalReachable()) {
            return;
        }

        $this->addColumns('leave_types');
        $this->addColumns('teams');
        $this->splitNames('leave_types');
        $this->backfillCodes('teams');
        $this->prefixStoredTeams();
    }

    public function down(): void
    {
        // Additive: a rollback would have to merge code back into name, which the app no longer reads.
    }

    private function addColumns(string $table): void
    {
        $schema = Schema::connection('portal');
        if (! $schema->hasTable($table)) {
            return;
        }

        $hasCode = $schema->hasColumn($table, 'code');
        $hasDescription = $schema->hasColumn($table, 'description');

        if (! $hasCode || ! $hasDescription) {
            $schema->table($table, function (Blueprint $blueprint) use ($hasCode, $hasDescription): void {
                if (! $hasCode) {
                    $blueprint->string('code', 10)->nullable()->after('id');
                }
                if (! $hasDescription) {
                    $blueprint->string('description', 255)->nullable();
                }
            });
        }

        if (! $schema->hasIndex($table, $table.'_code')) {
            $schema->table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique('code', $table.'_code');
            });
        }
    }

    /** Pull a leading number out of the name into the code: "02 Sick Leave" -> 02 + "Sick Leave". */
    private function splitNames(string $table): void
    {
        $schema = Schema::connection('portal');
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'code')) {
            return;
        }

        $connection = DB::connection('portal');
        foreach ($connection->table($table)->orderBy('id')->get(['id', 'name', 'code']) as $row) {
            $name = trim((string) $row->name);
            if (trim((string) $row->code) !== '') {
                continue;
            }
            if (preg_match('/^(\d+)\s+(.*)$/', $name, $matches) === 1) {
                $connection->table($table)->where('id', (int) $row->id)->update([
                    'code' => str_pad($matches[1], 2, '0', STR_PAD_LEFT),
                    'name' => trim($matches[2]),
                ]);
            }
        }

        $this->backfillCodes($table);
    }

    /** Give every row still without a code the next free two-digit one, in id order. */
    private function backfillCodes(string $table): void
    {
        $schema = Schema::connection('portal');
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'code')) {
            return;
        }

        $connection = DB::connection('portal');
        $used = [];
        $blank = [];
        foreach ($connection->table($table)->orderBy('id')->get(['id', 'code']) as $row) {
            $code = trim((string) $row->code);
            if ($code === '') {
                $blank[] = (int) $row->id;
            } else {
                $used[$code] = true;
            }
        }

        $next = 1;
        foreach ($blank as $id) {
            while (isset($used[str_pad((string) $next, 2, '0', STR_PAD_LEFT)])) {
                $next++;
            }
            $code = str_pad((string) $next, 2, '0', STR_PAD_LEFT);
            $connection->table($table)->where('id', $id)->update(['code' => $code]);
            $used[$code] = true;
            $next++;
        }
    }

    /** Old reimbursements store the bare office name; prefix the code so the value matches the list. */
    private function prefixStoredTeams(): void
    {
        $schema = Schema::connection('portal');
        if (! $schema->hasTable('reimbursements') || ! $schema->hasTable('teams')) {
            return;
        }

        $connection = DB::connection('portal');
        foreach ($connection->table('teams')->get(['code', 'name']) as $team) {
            $code = trim((string) $team->code);
            $name = trim((string) $team->name);
            if ($code === '' || $name === '') {
                continue;
            }
            $connection->table('reimbursements')
                ->where('team', $name)
                ->update(['team' => $code.' '.$name]);
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
