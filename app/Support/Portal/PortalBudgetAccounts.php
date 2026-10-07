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
    // accounts.account_type is VARCHAR(100).
    private const MAX_TYPE = 100;

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

        // Read fresh: an account added since this list was last read is on it.
        $this->envelopes->forgetAccounts();
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
            'holders' => $this->holders($accounts),
        ];
    }

    /**
     * A new account in the tracker, open from the start (2026-10-07). Its holder is a person: their email
     * is how the tracker ties an account to someone, and so to their team. A name is one account's, and
     * a person holds one open account, since the paid-from account is found by email.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string}}
     */
    public function create(Employee $actor, Request $request): array
    {
        $this->ledger->assertAvailable();
        $name = $this->input->validatedName($request, 'An account needs a name.', 'The account name');
        $holderId = $this->input->validatedId($request, 'holderId', 'That person does not exist.');
        $accountType = $this->input->validatedText($request, 'accountType', 'The account type', self::MAX_TYPE);
        $institution = $this->input->validatedText($request, 'institution', 'The bank', PortalBudgetInput::MAX_NAME);
        $holder = $holderId === null ? null : Employee::query()->whereKey($holderId)->first();
        if ($holderId !== null && $holder === null) {
            abort(422, 'That person does not exist.');
        }
        $holderName = $holder === null ? null : PortalSubmittedReportPresenter::memberName(
            is_string($holder->first_name) ? $holder->first_name : null,
            is_string($holder->last_name) ? $holder->last_name : null,
        );
        $email = $holder === null ? null : strtolower(trim((string) ($holder->email ?? '')));
        if ($email === '') {
            abort(422, $holderName.' has no email address, so the account cannot be tied to them.');
        }

        $id = $this->ledger->transaction(function () use ($name, $email, $holderName, $accountType, $institution): int {
            $tracker = $this->ledger->connection();
            if ($tracker->table('accounts')->whereRaw('LOWER(TRIM(account_name)) = ?', [mb_strtolower($name)])->lockForUpdate()->exists()) {
                abort(422, 'An account named '.$name.' already exists.');
            }
            if ($email !== null) {
                $held = $tracker->table('accounts')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->where('active', true)->lockForUpdate()->first(['account_name']);
                if ($held !== null) {
                    abort(422, $holderName.' already holds the open account '.$this->input->nameOr($held->account_name, 'on file').'.');
                }
            }
            $now = now('UTC')->format('Y-m-d H:i:s');

            return (int) $tracker->table('accounts')->insertGetId([
                'account_name' => $name,
                'account_type' => $accountType,
                'email' => $email,
                'institution' => $institution,
                // Open from the start, so it can take money and hold envelopes at once.
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.budget-accounts',
            PortalActivityCopy::addedAccount($name),
            (string) $id,
            $request,
            ['name' => $name, 'holder' => $holderName, 'accountType' => $accountType, 'institution' => $institution],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-accounts',
            'data' => ['id' => (string) $id, 'name' => $name],
        ];
    }

    /**
     * Who a new account can be opened for: active people with an email address who hold no open account.
     *
     * @param  list<array{id: int, name: string, team: ?string, active: bool, email: string}>  $accounts
     * @return list<array{employeeId: string, name: string, department: ?string}>
     */
    private function holders(array $accounts): array
    {
        $holding = [];
        foreach ($accounts as $account) {
            if ($account['active'] && $account['email'] !== '') {
                $holding[$account['email']] = true;
            }
        }

        $holders = [];
        $people = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereNotNull('email')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department']);
        foreach ($people as $person) {
            $email = strtolower(trim((string) $person->email));
            if ($email === '' || isset($holding[$email])) {
                continue;
            }
            $holders[] = [
                'employeeId' => (string) $person->getKey(),
                'name' => PortalSubmittedReportPresenter::memberName(
                    is_string($person->first_name) ? $person->first_name : null,
                    is_string($person->last_name) ? $person->last_name : null,
                ),
                'department' => $this->text($person->department ?? null),
            ];
        }

        return $holders;
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
        $note = PortalBudgetLedger::noteOf($row->description ?? null) ?? '';

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
