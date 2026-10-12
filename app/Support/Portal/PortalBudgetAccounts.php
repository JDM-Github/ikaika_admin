<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Finance / Accounts: every account in the transaction tracker with what it received, spent and holds,
 * the fund transfers between accounts -- pending, completed and cancelled -- and money added to one.
 *
 * The transfers list reads two places. A transfer still owed, or one settled from being owed, is a
 * row of its own. Money moved between accounts straight away -- an account transfer, or an envelope
 * transfer across two owners -- is only its ledger rows, which name both ends; each is listed once
 * from its Expense, as completed. A settled owed transfer wrote such rows too, so those are skipped.
 *
 * An account's balance is computed like an envelope's: every Credit linked to it less every Expense.
 * Its team is its holder's department, found through the holder's email the way the reimbursement
 * charges find it.
 */
final class PortalBudgetAccounts
{
    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalBudgetLedger $ledger,
        private readonly PortalBudgetInput $input,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, available: bool, currency: string, data: list<array<string, mixed>>, transfers: list<array<string, mixed>>}
     */
    public function list(Employee $actor): array
    {
        $page = ['section' => 'manage', 'resource' => 'budget-accounts', 'currency' => 'PHP'];
        if (! $this->envelopes->isAvailable()) {
            return $page + ['available' => false, 'data' => [], 'transfers' => []];
        }

        try {
            $balances = $this->ledger->connection()
                ->table('transactions_accounts as ta')
                ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
                ->groupBy('ta.account_id')
                ->select('ta.account_id')
                ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS received', [PortalBudgetLedger::TYPE_CREDIT])
                ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [PortalBudgetLedger::TYPE_EXPENSE])
                ->get()
                ->keyBy('account_id');
            $details = $this->ledger->connection()->table('accounts')->get(['id', 'account_type', 'institution'])->keyBy('id');
            $owed = $this->ledger->connection()
                ->table(PortalBudgetTransfers::TABLE)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
            $settled = $owed->pluck('source_transaction_id')->filter()->map(fn (mixed $id): int => (int) $id)->values()->all();
            $moved = $this->ledger->connection()
                ->table('transactions as t')
                ->join('transactions_transfer_source_accounts as s', 's.transaction_id', '=', 't.id')
                ->join('transactions_transfer_destination_accounts as d', 'd.transaction_id', '=', 't.id')
                ->where('t.type', PortalBudgetLedger::TYPE_EXPENSE)
                ->when($settled !== [], fn ($query) => $query->whereNotIn('t.id', $settled))
                ->get(['t.id', 't.transaction_name', 't.transaction_date', 't.amount', 't.description', 't.created_by', 's.account_id as source_account_id', 'd.account_id as destination_account_id']);
            $accounts = $this->envelopes->teamAccounts();
        } catch (QueryException) {
            abort(503, 'Accounts are unavailable right now.');
        }

        $ownEmail = strtolower(trim((string) ($actor->email ?? '')));
        $names = [];
        $data = [];
        foreach ($accounts as $account) {
            $names[$account['id']] = $account['name'];
            $balance = $balances->get($account['id']);
            $received = $this->input->cents($balance->received ?? 0);
            $spent = $this->input->cents($balance->spent ?? 0);
            $detail = $details->get($account['id']);
            $data[] = [
                'id' => (string) $account['id'],
                'name' => $account['name'],
                'team' => $account['team'],
                'accountType' => $this->text($detail->account_type ?? null),
                'institution' => $this->text($detail->institution ?? null),
                'active' => $account['active'],
                'received' => $this->input->amount($received),
                'spent' => $this->input->amount($spent),
                'remaining' => $this->input->amount($received - $spent),
                'isOwn' => $ownEmail !== '' && $account['email'] === $ownEmail,
            ];
        }

        $transfers = [
            ...$owed->map(fn (object $row): array => $this->presentTransfer($row, $names))->all(),
            ...$moved->map(fn (object $row): array => $this->presentMove($row, $names))->all(),
        ];
        // Newest first across both sources; a day the server could not read sinks to the end.
        usort($transfers, fn (array $left, array $right): int => strcmp((string) $right['createdOn'], (string) $left['createdOn']));

        return $page + [
            'available' => true,
            'data' => $data,
            'transfers' => $transfers,
        ];
    }

    /**
     * Money received into an account: a Credit linked to it, named as the tracker's own Funds Received
     * rows are. Only an open account takes it.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string, amount: float}}
     */
    public function addFunds(Employee $actor, int $accountId, Request $request): array
    {
        $this->ledger->assertAvailable();
        $cents = $this->input->movedAmount($request, 'Enter how much was received.');
        $receivedOn = $this->input->requiredDate($request, 'receivedOn', 'Choose the day the money was received.');
        $notes = $this->input->validatedText($request, 'notes', 'The notes');

        [$transactionId, $name] = $this->ledger->transaction(function () use ($actor, $accountId, $cents, $receivedOn, $notes): array {
            $account = $this->ledger->lockAccount($accountId, 404);
            $this->ledger->assertOpenAccount($account);
            $transactionId = $this->ledger->record($actor, PortalBudgetLedger::FUNDS_RECEIVED, PortalBudgetLedger::TYPE_CREDIT, $cents, $receivedOn, $notes, [
                'accountId' => $accountId,
            ]);

            return [$transactionId, $this->ledger->accountName($account)];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-accounts',
            PortalActivityCopy::addedAccountFunds($name, $this->input->peso($cents)),
            (string) $accountId,
            $request,
            ['transactionId' => $transactionId, 'amount' => $this->input->amount($cents), 'receivedOn' => $receivedOn],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-accounts',
            'data' => ['id' => (string) $transactionId, 'name' => $name, 'amount' => (float) $this->input->amount($cents)],
        ];
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function presentTransfer(object $row, array $names): array
    {
        $sourceId = $row->source_account_id === null ? null : (int) $row->source_account_id;
        $destinationId = $row->destination_account_id === null ? null : (int) $row->destination_account_id;

        return [
            'id' => (string) $row->id,
            'sourceAccountId' => $sourceId === null ? null : (string) $sourceId,
            'sourceName' => $sourceId === null ? null : ($names[$sourceId] ?? 'Account '.$sourceId),
            'destinationAccountId' => $destinationId === null ? null : (string) $destinationId,
            'destinationName' => $destinationId === null ? null : ($names[$destinationId] ?? 'Account '.$destinationId),
            'amount' => $this->input->amount($this->input->cents($row->amount)),
            'reason' => $this->text($row->reason),
            'status' => (string) $row->status,
            'createdOn' => $this->day($row->created_at),
            'createdBy' => $this->text($row->created_by),
            'settledOn' => $this->day($row->settled_at),
            'settledBy' => $this->text($row->settled_by),
        ];
    }

    /**
     * Money moved between two accounts straight away, read from its Expense row: completed the day it
     * was dated, by whoever recorded it. Its id is the ledger's, kept apart from the owed transfers'.
     *
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function presentMove(object $row, array $names): array
    {
        $day = $this->day($row->transaction_date);
        $by = $this->text($row->created_by);

        return $this->presentTransfer((object) [
            'id' => 'ledger-'.$row->id,
            'source_account_id' => $row->source_account_id,
            'destination_account_id' => $row->destination_account_id,
            'amount' => $row->amount,
            'reason' => $this->reasonOf($row),
            'status' => PortalBudgetTransfers::COMPLETED,
            'created_at' => $day,
            'created_by' => $by,
            'settled_at' => $day,
            'settled_by' => $by,
        ], $names);
    }

    // The note a move was written with, without the line every portal row ends with; else what it was.
    private function reasonOf(object $row): string
    {
        $note = trim((string) preg_replace('/\s*Recorded in the Ikaika portal by .*\.$/su', '', (string) ($row->description ?? '')));

        // "Expense - Account Transfer" names the row, not the move: the move is the account transfer.
        $name = preg_replace('/^Expense\s*-\s*/i', '', (string) ($row->transaction_name ?? ''));

        return $note !== '' ? $note : ($this->text($name) ?? 'Transfer');
    }

    private function day(mixed $stamp): ?string
    {
        $day = substr((string) ($stamp ?? ''), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
