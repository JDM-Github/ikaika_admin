<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Budget envelopes from the transaction tracker, and the charge an approved reimbursement makes
 * against one.
 *
 * An envelope's remaining balance is not a column. It is the tracker's own rule, carried over from
 * the Airtable base it was migrated from: every Credit transaction linked to the envelope, less
 * every Expense linked to it. budget_amount is a plan figure -- NULL on many envelopes and routinely
 * different from what was actually funded -- so it is shown, never used as the balance. Checked
 * against the exported rollups: the rule reproduces all 29 envelopes to the cent.
 *
 * So deducting a reimbursement means recording an Expense transaction linked to the envelope, and
 * that row is the transaction log. Nothing edits budget_amount, and un-approving a claim deletes
 * its charge rather than adding a reversing Credit: a Credit would read as new funding and inflate
 * the allocated total the Manage Requests header reports.
 *
 * Each charge carries source_key portal:reimbursement-claim:{id}. The column is unique, so a claim
 * can never be charged twice -- not by two items of one claim in one save, and not by two approvers
 * saving at the same moment.
 *
 * Each team's money is held in an account in one person's name, and the tracker says whose team it
 * is only through who holds it: an account's team is its holder's department, found by the
 * account's email. A charge is linked to the claimant's team account as well as to its envelope, so
 * it comes off both -- the account's Remaining Funds, as the Airtable base counts it, drops too.
 */
final class PortalBudgetEnvelopes
{
    // What an allocation reads as on the ledger: neither money received nor money spent.
    private const ALLOCATED_NAME = 'Funds Allocated';

    private const CONNECTION = 'tracker';

    private const PRODUCT = 'transaction-tracker';

    private const SOURCE_PREFIX = 'portal:reimbursement-claim:';

    private const TYPE_CREDIT = 'Credit';

    private const TYPE_EXPENSE = 'Expense';

    /**
     * Every account, whether it is still active, and the team its money belongs to. Read once per
     * request.
     *
     * @var list<array{id: int, name: string, team: ?string, active: bool}>|null
     */
    private ?array $accounts = null;

    public function __construct(private readonly PortalCloudinary $cloudinary) {}

    /** The tracker is switched on for this environment. Off, reimbursement approvals charge nothing. */
    public function isAvailable(): bool
    {
        $product = config('products.catalog.'.self::PRODUCT);

        return is_array($product) && (bool) ($product['enabled'] ?? false);
    }

    /**
     * Active envelopes with their balances, and the totals across them. Each envelope names the
     * budget codes it was raised under, so the review's picker can lay the envelopes out under them.
     *
     * @return array{
     *     section: string,
     *     resource: string,
     *     available: bool,
     *     currency: string,
     *     totals: array{allocated: float, spent: float, remaining: float}|null,
     *     data: list<array<string, mixed>>
     * }
     */
    public function summary(): array
    {
        if (! $this->isAvailable()) {
            return $this->envelope(false, null, []);
        }

        try {
            $rows = $this->balances();
            $codes = $this->envelopeCodes(array_map(fn (object $row): int => (int) $row->id, $rows));
        } catch (QueryException) {
            abort(503, 'Budget envelopes are unavailable right now.');
        }

        $allocated = 0;
        $spent = 0;
        $data = [];
        foreach ($rows as $row) {
            $funded = $this->cents($row->funded);
            $used = $this->cents($row->spent);
            $allocated += $funded;
            $spent += $used;
            $data[] = [
                'id' => (string) $row->id,
                'name' => $this->text($row->envelope_name) ?? 'Envelope '.$row->id,
                'group' => $this->text($row->group_name),
                'timePeriod' => $this->text($row->time_period),
                'planAmount' => $row->budget_amount === null ? null : $this->amount($this->cents($row->budget_amount)),
                'funded' => $this->amount($funded),
                'spent' => $this->amount($used),
                'remaining' => $this->amount($funded - $used),
                'codes' => $codes[(int) $row->id] ?? [],
            ];
        }

        return $this->envelope(true, [
            // Allocated is what was actually put into the envelopes, so remaining is always
            // allocated less spent on this header rather than a figure that fails to reconcile.
            'allocated' => $this->amount($allocated),
            'spent' => $this->amount($spent),
            'remaining' => $this->amount($allocated - $spent),
        ], $data);
    }

    /**
     * Every transaction in the tracker, newest first -- Credits and Expenses, on every envelope and
     * account, active or closed -- with the envelopes it is linked to and the team account it moved
     * through, and each active team account's balance. The ledger the Budget Tracker page reads.
     *
     * The balances are summed from these same rows -- every Credit linked to the account less every
     * Expense -- so the figures above the ledger always add up from the lines under them.
     *
     * @return array{section: string, resource: string, available: bool, currency: string, teams: list<array<string, mixed>>, data: list<array<string, mixed>>}
     */
    public function transactions(?Employee $actor = null): array
    {
        $page = ['section' => 'manage', 'resource' => 'budget-transactions', 'currency' => 'PHP'];
        if (! $this->isAvailable()) {
            return $page + ['available' => false, 'teams' => [], 'data' => []];
        }

        try {
            $rows = $this->connection()
                ->table('transactions')
                // A row with no Credit/Expense type is not a money movement; the portal's strict
                // parser refuses one such row and would lose the whole ledger, so it never travels.
                ->whereIn('type', [self::TYPE_CREDIT, self::TYPE_EXPENSE])
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get(['id', 'transaction_name', 'transaction_date', 'amount', 'type', 'payor_payee', 'description', 'created_by', 'source_key']);
            $envelopes = $this->connection()
                ->table('transactions_envelopes as te')
                ->join('envelopes_budget as e', 'e.id', '=', 'te.envelope_id')
                ->orderBy('e.envelope_name')
                ->get(['te.transaction_id', 'e.envelope_name'])
                ->groupBy('transaction_id');
            $links = $this->connection()
                ->table('transactions_accounts')
                ->orderBy('account_id')
                ->get(['transaction_id', 'account_id'])
                ->groupBy('transaction_id');
            // The budget codes a line is recorded against, so a page can narrow the ledger to one code.
            $codeLinks = $this->connection()
                ->table('transactions_budget_codes')
                ->orderBy('budget_code_id')
                ->get(['transaction_id', 'budget_code_id'])
                ->groupBy('transaction_id');
            $accounts = collect($this->teamAccounts())->keyBy('id');
            // Each transaction's receipts: the ones migrated from Airtable and the ones added here since.
            $receipts = $this->connection()
                ->table('attachments')
                ->where('table_name', PortalBudgetTransactions::RECEIPT_TABLE)
                ->where('field_name', PortalBudgetTransactions::RECEIPT_FIELD)
                ->orderBy('id')
                ->get(['record_id', 'file_url', 'file_name', 'mime_type'])
                ->groupBy('record_id');
        } catch (QueryException) {
            abort(503, 'Budget transactions are unavailable right now.');
        }

        $data = [];
        $received = [];
        $spent = [];
        // What each account holds on rows tied to no envelope: money not yet given to one.
        $unallocated = [];
        foreach ($rows as $row) {
            // Money moved inside one account: kept on the ledger, but neither received nor spent.
            $isAllocation = in_array((string) $row->transaction_name, PortalBudgetLedger::ALLOCATIONS, true);
            foreach ($links->get($row->id) ?? [] as $linked) {
                $accountId = (int) $linked->account_id;
                $sign = 0;
                if ((string) $row->type === self::TYPE_CREDIT) {
                    $received[$accountId] = ($received[$accountId] ?? 0) + ($isAllocation ? 0 : $this->cents($row->amount));
                    $sign = 1;
                } elseif ((string) $row->type === self::TYPE_EXPENSE) {
                    $spent[$accountId] = ($spent[$accountId] ?? 0) + ($isAllocation ? 0 : $this->cents($row->amount));
                    $sign = -1;
                }
                if (! $envelopes->has($row->id)) {
                    $unallocated[$accountId] = ($unallocated[$accountId] ?? 0) + $sign * $this->cents($row->amount);
                }
            }
            // An allocation lists once (2026-10-08), as the money given to its envelope: the row out of
            // the account's unallocated funds is its mirror, counted above but not shown.
            if ((string) $row->transaction_name === PortalBudgetLedger::ALLOCATION_OUT) {
                continue;
            }
            $link = $links->get($row->id)?->first();
            $account = $link === null ? null : $accounts->get((int) $link->account_id);
            $date = substr((string) ($row->transaction_date ?? ''), 0, 10);
            $data[] = [
                'id' => (string) $row->id,
                'name' => $isAllocation ? self::ALLOCATED_NAME : ($this->text($row->transaction_name) ?? (string) $row->type),
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
                'type' => (string) $row->type,
                'amount' => $this->amount($this->cents($row->amount)),
                'payorPayee' => $this->text($row->payor_payee),
                // What the person wrote, without the line older portal rows carry (PortalBudgetLedger::noteOf).
                'description' => PortalBudgetLedger::noteOf($row->description),
                'envelopes' => $envelopes->get($row->id)?->map(fn (object $envelope): string => $this->text($envelope->envelope_name) ?? 'Envelope')->values()->all() ?? [],
                'budgetCodeIds' => $codeLinks->get($row->id)?->map(fn (object $link): string => (string) $link->budget_code_id)->values()->all() ?? [],
                'accountName' => $account['name'] ?? null,
                'team' => $account['team'] ?? null,
                'recordedBy' => $this->text($row->created_by),
                'receipts' => ($receipts->get($row->id) ?? collect())
                    ->filter(fn (object $receipt): bool => $this->text($receipt->file_url) !== null)
                    ->map(fn (object $receipt): array => [
                        'fileName' => $this->text($receipt->file_name) ?? 'Receipt',
                        'fileUrl' => (string) $receipt->file_url,
                        'mimeType' => $this->text($receipt->mime_type),
                        'thumbUrl' => $this->cloudinary->thumbUrl((string) $receipt->file_url, (string) ($receipt->mime_type ?? '')),
                    ])
                    ->values()
                    ->all(),
                // Charged by an approval in the portal, rather than entered in the tracker itself.
                'isPortalCharge' => str_starts_with((string) ($row->source_key ?? ''), self::SOURCE_PREFIX),
                // Half of an allocation: listed with its envelope, never as money in or out (2026-10-08).
                'isAllocation' => $isAllocation,
                // Entered by hand, so Edit transactions may change its details (2026-10-13).
                'isEditable' => PortalBudgetTransactions::isEditable($row->transaction_name, $row->source_key),
            ];
        }

        $ownEmail = strtolower(trim((string) ($actor->email ?? '')));
        $teams = [];
        foreach ($this->teamAccounts() as $account) {
            if (! $account['active']) {
                continue;
            }
            $in = $received[$account['id']] ?? 0;
            $out = $spent[$account['id']] ?? 0;
            $teams[] = [
                'accountId' => (string) $account['id'],
                'accountName' => $account['name'],
                'team' => $account['team'],
                'received' => $this->amount($in),
                'spent' => $this->amount($out),
                'remaining' => $this->amount($in - $out),
                'unallocated' => $this->amount($unallocated[$account['id']] ?? 0),
                // The reader's own account, which a transaction they add is paid from by default.
                'isOwn' => $ownEmail !== '' && $account['email'] === $ownEmail,
            ];
        }

        return $page + ['available' => true, 'teams' => $teams, 'data' => $data];
    }

    /**
     * Every envelope, open or closed, under the budget code it was raised for -- the Budget Tracker's
     * Envelopes tab, laid out the way the tracker's own Envelopes - Budget view groups them. An
     * envelope linked to no code is listed under unassigned; one linked to two would sit under both.
     *
     * Each code carries what was received against it: every Credit linked to the code itself, the
     * tracker's own Actual Credited. That is not what its envelopes were credited -- money moves
     * between envelopes, and a transfer can land before its envelope is linked to the code -- so it is
     * the figure to hold against the quotation.
     *
     * @return array{section: string, resource: string, available: bool, currency: string, data: list<array<string, mixed>>, unassigned: list<array<string, mixed>>}
     */
    public function budgetCodes(): array
    {
        $page = ['section' => 'manage', 'resource' => 'budget-codes', 'currency' => 'PHP'];
        if (! $this->isAvailable()) {
            return $page + ['available' => false, 'data' => [], 'unassigned' => []];
        }

        try {
            $owners = $this->connection()
                ->table('envelopes_accounts as ea')
                ->join('accounts as a', 'a.id', '=', 'ea.account_id')
                ->where('a.active', true)
                ->orderBy('a.id')
                ->get(['ea.envelope_id', 'a.id', 'a.account_name'])
                ->groupBy('envelope_id')
                ->map(fn (Collection $rows): ?object => $rows->first());
            $envelopes = collect($this->connection()
                ->table('envelopes_budget as e')
                ->leftJoin('transactions_envelopes as te', 'te.envelope_id', '=', 'e.id')
                ->leftJoin('transactions as t', 't.id', '=', 'te.transaction_id')
                ->groupBy('e.id', 'e.envelope_name', 'e.group_name', 'e.time_period', 'e.budget_amount', 'e.active', 'e.budget_notes')
                ->orderBy('e.group_name')
                ->orderBy('e.envelope_name')
                ->select(['e.id', 'e.envelope_name', 'e.group_name', 'e.time_period', 'e.budget_amount', 'e.active', 'e.budget_notes'])
                ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS funded', [self::TYPE_CREDIT])
                ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [self::TYPE_EXPENSE])
                ->get()
                ->all())
                ->mapWithKeys(fn (object $row): array => [(int) $row->id => $this->presentEnvelope($row, $owners->get($row->id))]);
            $codes = $this->connection()
                ->table('budget_codes')
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'transfer_date_received', 'expected_amount_quotation']);
            $links = $this->connection()
                ->table('envelopes_budget_codes')
                ->get(['envelope_id', 'budget_code_id'])
                ->groupBy('budget_code_id');
            $receivedByCode = $this->connection()
                ->table('transactions_budget_codes as tb')
                ->join('transactions as t', 't.id', '=', 'tb.transaction_id')
                ->where('t.type', self::TYPE_CREDIT)
                ->groupBy('tb.budget_code_id')
                ->select('tb.budget_code_id')
                ->selectRaw('COALESCE(SUM(t.amount), 0) AS received')
                ->pluck('received', 'budget_code_id');
            // Which envelopes each code's money went into, linked or not, so Add Transaction can find them by code.
            $creditedByCode = $this->connection()
                ->table('transactions_budget_codes as tb')
                ->join('transactions as t', 't.id', '=', 'tb.transaction_id')
                ->join('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
                ->where('t.type', self::TYPE_CREDIT)
                ->distinct()
                ->get(['tb.budget_code_id', 'te.envelope_id'])
                ->groupBy('budget_code_id');
        } catch (QueryException) {
            abort(503, 'Budget codes are unavailable right now.');
        }

        $linked = [];
        $data = [];
        foreach ($codes as $code) {
            $members = [];
            foreach ($links->get($code->id) ?? [] as $link) {
                $envelope = $envelopes->get((int) $link->envelope_id);
                if ($envelope !== null) {
                    $members[] = $envelope;
                    $linked[(int) $link->envelope_id] = true;
                }
            }
            $received = (string) ($code->transfer_date_received ?? '');
            $data[] = [
                'id' => (string) $code->id,
                'name' => $this->text($code->name) ?? 'Budget code '.$code->id,
                'description' => $this->text($code->description),
                'receivedOn' => preg_match('/^\d{4}-\d{2}-\d{2}/', $received) === 1 ? substr($received, 0, 10) : null,
                'expectedAmount' => $code->expected_amount_quotation === null
                    ? null
                    : $this->amount($this->cents($code->expected_amount_quotation)),
                'received' => $this->amount($this->cents($receivedByCode->get($code->id) ?? 0)),
                'envelopes' => $members,
                'fundedEnvelopeIds' => collect($creditedByCode->get($code->id) ?? [])
                    ->map(fn (object $row): string => (string) $row->envelope_id)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
            ];
        }

        return $page + [
            'available' => true,
            'data' => $data,
            'unassigned' => $envelopes->reject(fn (array $envelope, int $id): bool => isset($linked[$id]))->values()->all(),
        ];
    }

    /**
     * Runs a batch of decisions inside a tracker transaction, so a charge and the approval that caused
     * it commit or roll back together. The two databases cannot share one transaction; the portal's
     * is opened inside this one, which narrows the gap to the instant between the two commits.
     */
    public function withinLedger(Closure $work): mixed
    {
        if (! $this->isAvailable()) {
            return $work();
        }

        return $this->connection()->transaction($work);
    }

    /**
     * The charge an item carries, if any.
     *
     * @return array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}|null
     */
    public function chargeFor(int $itemId): ?array
    {
        $row = $this->chargeRows([$itemId])->first();

        return $row === null ? null : $this->presentCharge($row);
    }

    /**
     * The charges standing against any of these items, keyed by the item each one carries.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}>
     */
    public function chargesFor(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $charges = [];
        foreach ($this->chargeRows($itemIds)->all() as $row) {
            $charge = $this->presentCharge($row);
            $charges[$charge['claimId']] ??= $charge;
        }

        return $charges;
    }

    /**
     * Charges an approved item to the envelope the approver picked for it. The charge carries the
     * item's id, so setting the item back finds exactly this charge and nothing of its claim's others.
     *
     * The envelope row is locked before its balance is read, so two approvers charging the same
     * envelope at once are serialised: the second reads the balance the first one left.
     *
     * @param  array{id: int, memberName: string, filedOn: string, amountCents: int, items: list<string>, department?: ?string}  $claim
     * @return array{transactionId: int, envelopeId: int, envelopeName: string, amount: float, remainingAfter: float, overspent: bool, accountId: ?string, accountName: ?string, team: ?string}
     */
    public function charge(Employee $actor, array $claim, int $envelopeId, bool $allowOverspend): array
    {
        $envelope = $this->connection()
            ->table('envelopes_budget')
            ->where('id', $envelopeId)
            ->lockForUpdate()
            ->first(['id', 'envelope_name', 'active']);

        if ($envelope === null) {
            abort(422, 'That budget envelope does not exist.');
        }
        $name = $this->text($envelope->envelope_name) ?? 'That envelope';
        if (! (bool) $envelope->active) {
            abort(422, $name.' is not an active budget envelope.');
        }

        $remaining = $this->remainingCents($envelopeId);
        $overspent = $claim['amountCents'] > $remaining;
        if ($overspent && ! $allowOverspend) {
            abort(422, sprintf(
                '%s has %s left, but this item is %s. Confirm the overspend to charge it anyway.',
                $name,
                $this->peso($remaining),
                $this->peso($claim['amountCents']),
            ));
        }

        $now = now('UTC')->format('Y-m-d H:i:s');
        $actorName = PortalActivityCopy::displayName($actor);
        try {
            $transactionId = (int) $this->connection()->table('transactions')->insertGetId([
                'transaction_name' => 'Reimbursement',
                'transaction_date' => $now,
                'amount' => number_format($claim['amountCents'] / 100, 2, '.', ''),
                'type' => self::TYPE_EXPENSE,
                'payor_payee' => $claim['memberName'],
                'description' => $this->description($claim, $actorName, $overspent),
                'reviewed_by_email' => is_string($actor->email ?? null) ? $actor->email : null,
                'approval_date' => $now,
                'created_by' => $actorName,
                'created_at' => $now,
                'updated_at' => $now,
                'source_key' => self::SOURCE_PREFIX.$claim['id'],
            ]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This claim was charged by another approver a moment ago. Reload the queue and check it.');
        }

        $this->connection()->table('transactions_envelopes')->insert([
            'transaction_id' => $transactionId,
            'envelope_id' => $envelopeId,
        ]);

        // A claimant whose team holds no account is charged to the envelope alone, as before teams.
        $account = $this->accountForTeam($claim['department'] ?? null);
        if ($account !== null) {
            $this->book($transactionId, $account['id']);
        }

        return [
            'transactionId' => $transactionId,
            'envelopeId' => $envelopeId,
            'envelopeName' => $name,
            'amount' => $this->amount($claim['amountCents']),
            'remainingAfter' => $this->amount($remaining - $claim['amountCents']),
            'overspent' => $overspent,
            'accountId' => $account === null ? null : (string) $account['id'],
            'accountName' => $account === null ? null : $account['name'],
            'team' => $account === null ? null : $account['team'],
        ];
    }

    /**
     * Takes one item's share out of a charge that covers its whole claim -- one made before items
     * were charged one by one -- and hands what is left to the item that now carries it. Shrinking
     * returns money to the envelope, so it needs neither an open envelope nor a go-ahead.
     *
     * @param  array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}  $existing
     * @param  array{id: int, memberName: string, filedOn: string, amountCents: int, items: list<string>, department?: ?string}  $claim
     * @return array{transactionId: int, envelopeId: int, envelopeName: string, previousAmount: float, amount: float}
     */
    public function shrink(Employee $actor, array $existing, array $claim): array
    {
        $this->connection()->table('transactions')->where('id', $existing['transactionId'])->update([
            'amount' => number_format($claim['amountCents'] / 100, 2, '.', ''),
            'description' => $this->description($claim, PortalActivityCopy::displayName($actor), false),
            'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
            'source_key' => self::SOURCE_PREFIX.$claim['id'],
        ]);

        return [
            'transactionId' => $existing['transactionId'],
            'envelopeId' => $existing['envelopeId'],
            'envelopeName' => $existing['envelopeName'],
            'previousAmount' => $existing['amount'],
            'amount' => $this->amount($claim['amountCents']),
        ];
    }

    /**
     * Books the charges recorded before a charge carried its team account, each to the account of
     * the claimant's team. A charge already booked is skipped, so running this again books nothing
     * more; one whose claimant's team holds no account is reported and left as it was.
     *
     * @param  Closure(int): ?string  $departmentOfClaim  the claimant's department, by claim id
     * @return list<array{transactionId: int, claimId: int, team: ?string, accountName: ?string}>
     */
    public function bookUnlinkedCharges(Closure $departmentOfClaim): array
    {
        $charges = $this->connection()
            ->table('transactions as t')
            ->leftJoin('transactions_accounts as ta', 'ta.transaction_id', '=', 't.id')
            ->where('t.source_key', 'like', self::SOURCE_PREFIX.'%')
            ->whereNull('ta.transaction_id')
            ->orderBy('t.id')
            ->get(['t.id', 't.source_key']);

        $booked = [];
        foreach ($charges as $charge) {
            $claimId = (int) substr((string) $charge->source_key, strlen(self::SOURCE_PREFIX));
            $team = $departmentOfClaim($claimId);
            $account = $this->accountForTeam($team);
            if ($account !== null) {
                $this->book((int) $charge->id, $account['id']);
            }
            $booked[] = [
                'transactionId' => (int) $charge->id,
                'claimId' => $claimId,
                'team' => $team,
                'accountName' => $account === null ? null : $account['name'],
            ];
        }

        return $booked;
    }

    /**
     * Removes a charge once what it covers is no longer approved. The junction rows go with it
     * through their ON DELETE CASCADE.
     *
     * @param  array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}  $charge
     * @return array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}
     */
    public function release(array $charge): array
    {
        $this->connection()->table('transactions')->where('id', $charge['transactionId'])->delete();

        return $charge;
    }

    /**
     * @param  list<int>  $itemIds
     * @return Collection<int, object>
     */
    private function chargeRows(array $itemIds): Collection
    {
        return $this->connection()
            ->table('transactions as t')
            ->leftJoin('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->leftJoin('envelopes_budget as e', 'e.id', '=', 'te.envelope_id')
            ->whereIn('t.source_key', array_map(fn (int $id): string => self::SOURCE_PREFIX.$id, $itemIds))
            ->orderBy('t.id')
            ->get(['t.id', 't.amount', 't.source_key', 'e.id as envelope_id', 'e.envelope_name']);
    }

    /**
     * @return array{transactionId: int, claimId: int, envelopeId: int, envelopeName: string, amount: float}
     */
    private function presentCharge(object $row): array
    {
        return [
            'transactionId' => (int) $row->id,
            'claimId' => (int) substr((string) $row->source_key, strlen(self::SOURCE_PREFIX)),
            'envelopeId' => (int) $row->envelope_id,
            'envelopeName' => $this->text($row->envelope_name) ?? 'Envelope',
            'amount' => $this->amount($this->cents($row->amount)),
        ];
    }

    /**
     * One envelope and its balance, open or closed, as the Envelopes tab draws it.
     *
     * @return array<string, mixed>
     */
    private function presentEnvelope(object $row, ?object $owner = null): array
    {
        $funded = $this->cents($row->funded);
        $used = $this->cents($row->spent);

        return [
            'id' => (string) $row->id,
            'name' => $this->text($row->envelope_name) ?? 'Envelope '.$row->id,
            'group' => $this->text($row->group_name),
            'timePeriod' => $this->text($row->time_period),
            'planAmount' => $row->budget_amount === null ? null : $this->amount($this->cents($row->budget_amount)),
            'funded' => $this->amount($funded),
            'spent' => $this->amount($used),
            'remaining' => $this->amount($funded - $used),
            'active' => (bool) $row->active,
            'notes' => $this->text($row->budget_notes ?? null),
            // The open account whose money the envelope holds; null when nobody owns it.
            'ownerAccountId' => $owner === null ? null : (string) $owner->id,
            'ownerName' => $owner === null ? null : ($this->text($owner->account_name) ?? 'Account '.$owner->id),
        ];
    }

    private function book(int $transactionId, int $accountId): void
    {
        $this->connection()->table('transactions_accounts')->insert([
            'transaction_id' => $transactionId,
            'account_id' => $accountId,
        ]);
    }

    /**
     * The account a claimant's department draws on: the first active account held by someone in
     * that department. Null when the department holds none.
     *
     * @return array{id: int, name: string, team: ?string, active: bool, email: string}|null
     */
    private function accountForTeam(?string $department): ?array
    {
        $team = $this->teamKey($department);
        if ($team === null) {
            return null;
        }
        foreach ($this->teamAccounts() as $account) {
            // A closed account takes no new charges, whoever holds it.
            if ($account['active'] && $this->teamKey($account['team']) === $team) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Every account with its holder's department as its team, matched on the holder's email.
     *
     * @return list<array{id: int, name: string, team: ?string, active: bool, email: string}>
     */
    public function teamAccounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $accounts = [];
        $rows = $this->connection()
            ->table('accounts')
            ->orderBy('id')
            ->get(['id', 'account_name', 'email', 'active']);
        foreach ($rows as $row) {
            $email = strtolower(trim((string) ($row->email ?? '')));
            $department = $email === ''
                ? null
                : Employee::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->value('department');
            $accounts[] = [
                'id' => (int) $row->id,
                'name' => $this->text($row->account_name) ?? 'Account '.$row->id,
                'team' => $this->text($department),
                'active' => (bool) $row->active,
                'email' => $email,
            ];
        }

        return $this->accounts = $accounts;
    }

    // Reads the accounts again on the next ask, so a list read after an account was added includes it.
    public function forgetAccounts(): void
    {
        $this->accounts = null;
    }

    // Departments match loosely: "MIS" and "mis " are one team.
    private function teamKey(?string $team): ?string
    {
        $key = strtolower(trim((string) $team));

        return $key === '' ? null : $key;
    }

    public function remainingCents(int $envelopeId): int
    {
        $row = $this->connection()
            ->table('transactions_envelopes as te')
            ->join('transactions as t', 't.id', '=', 'te.transaction_id')
            ->where('te.envelope_id', $envelopeId)
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS funded', [self::TYPE_CREDIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [self::TYPE_EXPENSE])
            ->first();

        return $this->cents($row->funded ?? 0) - $this->cents($row->spent ?? 0);
    }

    /**
     * The budget codes each envelope was raised under, keyed by envelope, in name order. Most sit
     * under one code; an envelope raised under none gets no entry.
     *
     * @param  list<int>  $envelopeIds
     * @return array<int, list<array{id: string, name: string, description: ?string}>>
     */
    private function envelopeCodes(array $envelopeIds): array
    {
        if ($envelopeIds === []) {
            return [];
        }

        $codes = [];
        $rows = $this->connection()
            ->table('envelopes_budget_codes as ebc')
            ->join('budget_codes as c', 'c.id', '=', 'ebc.budget_code_id')
            ->whereIn('ebc.envelope_id', $envelopeIds)
            ->orderBy('c.name')
            ->get(['ebc.envelope_id', 'c.id', 'c.name', 'c.description']);
        foreach ($rows as $row) {
            $codes[(int) $row->envelope_id][] = [
                'id' => (string) $row->id,
                'name' => $this->text($row->name) ?? 'Budget code '.$row->id,
                'description' => $this->text($row->description),
            ];
        }

        return $codes;
    }

    /**
     * @return list<object>
     */
    private function balances(): array
    {
        return $this->connection()
            ->table('envelopes_budget as e')
            ->leftJoin('transactions_envelopes as te', 'te.envelope_id', '=', 'e.id')
            ->leftJoin('transactions as t', 't.id', '=', 'te.transaction_id')
            // An inactive envelope is closed; nothing new should be charged to it or counted as open.
            ->where('e.active', true)
            ->groupBy('e.id', 'e.envelope_name', 'e.group_name', 'e.time_period', 'e.budget_amount')
            ->orderBy('e.group_name')
            ->orderBy('e.envelope_name')
            ->select(['e.id', 'e.envelope_name', 'e.group_name', 'e.time_period', 'e.budget_amount'])
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS funded', [self::TYPE_CREDIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [self::TYPE_EXPENSE])
            ->get()
            ->all();
    }

    /**
     * @param  array{id: int, memberName: string, filedOn: string, amountCents: int, items: list<string>, department?: ?string}  $claim
     */
    private function description(array $claim, string $actorName, bool $overspent): string
    {
        $count = count($claim['items']);
        $text = sprintf(
            'Reimbursement for %s, filed %s: %d %s -- %s. Approved in the Ikaika portal by %s.',
            $claim['memberName'],
            $claim['filedOn'] === '' ? 'on an unknown date' : $claim['filedOn'],
            $count,
            $count === 1 ? 'item' : 'items',
            implode(', ', $claim['items']),
            $actorName,
        );

        return $overspent
            ? $text.' Charged over the envelope\'s remaining balance, confirmed by the approver.'
            : $text;
    }

    /**
     * @param  array{allocated: float, spent: float, remaining: float}|null  $totals
     * @param  list<array<string, mixed>>  $data
     * @return array{section: string, resource: string, available: bool, currency: string, totals: array{allocated: float, spent: float, remaining: float}|null, data: list<array<string, mixed>>}
     */
    private function envelope(bool $available, ?array $totals, array $data): array
    {
        return [
            'section' => 'manage',
            'resource' => 'budget-envelopes',
            'available' => $available,
            'currency' => 'PHP',
            'totals' => $totals,
            'data' => $data,
        ];
    }

    // Money is compared in whole centavos: DECIMAL arrives as a string, and floats drift on sums.
    private function cents(mixed $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    private function amount(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function peso(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'₱'.number_format(abs($cents) / 100, 2);
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection(self::CONNECTION);
    }
}
