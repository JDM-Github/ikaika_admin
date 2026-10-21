<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Finance / Transactions' payor list (2026-10-21, user): the names Add Transaction offers for a payor or
 * payee, so the same one is picked rather than retyped.
 *
 * The list is every name the tracker's transactions already carry, most used first, plus the names added
 * here by hand, less any taken off it. Taking a name off only hides it from the list: the transactions
 * that carry it keep it, and adding it again brings it back. Names match without case, as the database
 * compares them.
 */
final class PortalBudgetPayors
{
    public const TABLE = 'finance_payors';

    public const MAX_NAME = 191;

    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalBudgetLedger $ledger,
        private readonly PortalBudgetInput $input,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, data: list<array{name: string, uses: int}>}
     */
    public function list(): array
    {
        $byKey = [];
        foreach ($this->ledgerUses() as $name => $uses) {
            $byKey[mb_strtolower($name)] = ['name' => $name, 'uses' => $uses];
        }
        foreach ($this->rows() as $row) {
            $key = mb_strtolower($row['name']);
            if ($row['isHidden']) {
                unset($byKey[$key]);

                continue;
            }
            $byKey[$key] ??= ['name' => $row['name'], 'uses' => 0];
        }

        $data = array_values($byKey);
        usort($data, static fn (array $left, array $right): int => [$right['uses'], mb_strtolower($left['name'])] <=> [$left['uses'], mb_strtolower($right['name'])]);

        return ['section' => 'manage', 'resource' => 'budget-payors', 'data' => $data];
    }

    /**
     * @return array{section: string, resource: string, data: list<array{name: string, uses: int}>}
     */
    public function add(Employee $actor, Request $request): array
    {
        $name = $this->validatedName($request);
        $this->keep($actor, $name, false);
        $this->audit->record($actor, PortalLogAction::POST, 'manage.budget-payors', PortalActivityCopy::addedPayor($name), null, $request, ['name' => $name]);

        return $this->list();
    }

    /**
     * @return array{section: string, resource: string, data: list<array{name: string, uses: int}>}
     */
    public function remove(Employee $actor, Request $request): array
    {
        $name = $this->validatedName($request);
        $this->keep($actor, $name, true);
        $this->audit->record($actor, PortalLogAction::DELETE, 'manage.budget-payors', PortalActivityCopy::removedPayor($name), null, $request, ['name' => $name]);

        return $this->list();
    }

    private function validatedName(Request $request): string
    {
        $name = $this->input->validatedName($request, 'Type the payor or payee to keep on the list.', 'The payor or payee');
        if (mb_strlen($name) > self::MAX_NAME) {
            abort(422, 'A payor or payee on the list cannot exceed '.self::MAX_NAME.' characters.');
        }

        return $name;
    }

    // One row per name: adding or taking off again only flips whether it is hidden.
    private function keep(Employee $actor, string $name, bool $isHidden): void
    {
        DB::connection('portal')->table(self::TABLE)->upsert(
            [[
                'name' => $name,
                'is_hidden' => $isHidden,
                'updated_by' => (int) $actor->getKey(),
                'date_updated' => Carbon::now('UTC')->toDateTimeString(),
            ]],
            ['name'],
            ['is_hidden', 'updated_by', 'date_updated'],
        );
    }

    /**
     * How many transactions carry each payor or payee, by the spelling the database groups them under.
     * Nothing when the tracker is switched off here or cannot be read: the hand-kept names still show.
     *
     * @return array<string, int>
     */
    private function ledgerUses(): array
    {
        if (! $this->envelopes->isAvailable()) {
            return [];
        }
        try {
            $rows = $this->ledger->connection()
                ->table('transactions')
                ->whereNotNull('payor_payee')
                ->where('payor_payee', '!=', '')
                ->groupBy('payor_payee')
                ->select('payor_payee')
                ->selectRaw('COUNT(*) AS uses')
                ->get();
        } catch (QueryException) {
            return [];
        }

        $uses = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->payor_payee);
            if ($name !== '') {
                $uses[$name] = ($uses[$name] ?? 0) + (int) $row->uses;
            }
        }

        return $uses;
    }

    /**
     * @return list<array{name: string, isHidden: bool}>
     */
    private function rows(): array
    {
        try {
            return DB::connection('portal')->table(self::TABLE)->get(['name', 'is_hidden'])
                ->map(static fn (object $row): array => ['name' => trim((string) $row->name), 'isHidden' => (bool) $row->is_hidden])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }
}
