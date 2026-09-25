<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

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

    /** The tracker is switched on for this environment. Off, reimbursement approvals charge nothing. */
    public function isAvailable(): bool
    {
        $product = config('products.catalog.'.self::PRODUCT);

        return is_array($product) && (bool) ($product['enabled'] ?? false);
    }

    /**
     * Active envelopes with their balances, and the totals across them.
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
    public function transactions(): array
    {
        $page = ['section' => 'manage', 'resource' => 'budget-transactions', 'currency' => 'PHP'];
        if (! $this->isAvailable()) {
            return $page + ['available' => false, 'teams' => [], 'data' => []];
        }

        try {
            $rows = $this->connection()
                ->table('transactions')
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
            $accounts = collect($this->teamAccounts())->keyBy('id');
        } catch (QueryException) {
            abort(503, 'Budget transactions are unavailable right now.');
        }

        $data = [];
        $received = [];
        $spent = [];
        foreach ($rows as $row) {
            foreach ($links->get($row->id) ?? [] as $linked) {
                $accountId = (int) $linked->account_id;
                if ((string) $row->type === self::TYPE_CREDIT) {
                    $received[$accountId] = ($received[$accountId] ?? 0) + $this->cents($row->amount);
                } elseif ((string) $row->type === self::TYPE_EXPENSE) {
                    $spent[$accountId] = ($spent[$accountId] ?? 0) + $this->cents($row->amount);
                }
            }
            $link = $links->get($row->id)?->first();
            $account = $link === null ? null : $accounts->get((int) $link->account_id);
            $date = substr((string) ($row->transaction_date ?? ''), 0, 10);
            $data[] = [
                'id' => (string) $row->id,
                'name' => $this->text($row->transaction_name) ?? (string) $row->type,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
                'type' => (string) $row->type,
                'amount' => $this->amount($this->cents($row->amount)),
                'payorPayee' => $this->text($row->payor_payee),
                'description' => $this->text($row->description),
                'envelopes' => $envelopes->get($row->id)?->map(fn (object $envelope): string => $this->text($envelope->envelope_name) ?? 'Envelope')->values()->all() ?? [],
                'accountName' => $account['name'] ?? null,
                'team' => $account['team'] ?? null,
                'recordedBy' => $this->text($row->created_by),
                // Charged by an approval in the portal, rather than entered in the tracker itself.
                'isPortalCharge' => str_starts_with((string) ($row->source_key ?? ''), self::SOURCE_PREFIX),
            ];
        }

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
            ];
        }

        return $page + ['available' => true, 'teams' => $teams, 'data' => $data];
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
     * The charge already standing against a claim, if any.
     *
     * @return array{transactionId: int, envelopeId: int, envelopeName: string, amount: float}|null
     */
    public function chargeFor(int $claimId): ?array
    {
        $row = $this->connection()
            ->table('transactions as t')
            ->leftJoin('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->leftJoin('envelopes_budget as e', 'e.id', '=', 'te.envelope_id')
            ->where('t.source_key', self::SOURCE_PREFIX.$claimId)
            ->first(['t.id', 't.amount', 'e.id as envelope_id', 'e.envelope_name']);

        if ($row === null) {
            return null;
        }

        return [
            'transactionId' => (int) $row->id,
            'envelopeId' => (int) $row->envelope_id,
            'envelopeName' => $this->text($row->envelope_name) ?? 'Envelope',
            'amount' => $this->amount($this->cents($row->amount)),
        ];
    }

    /**
     * Charges an approved claim's total to an envelope.
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
                '%s has %s left, but this claim is %s. Confirm the overspend to charge it anyway.',
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
     * Removes a claim's charge once it is no longer approved. The junction row goes with it through
     * its ON DELETE CASCADE.
     *
     * @return array{transactionId: int, envelopeId: int, envelopeName: string, amount: float}|null
     */
    public function release(int $claimId): ?array
    {
        $charge = $this->chargeFor($claimId);
        if ($charge === null) {
            return null;
        }

        $this->connection()->table('transactions')->where('id', $charge['transactionId'])->delete();

        return $charge;
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
     * @return array{id: int, name: string, team: ?string, active: bool}|null
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
     * @return list<array{id: int, name: string, team: ?string, active: bool}>
     */
    private function teamAccounts(): array
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
            ];
        }

        return $this->accounts = $accounts;
    }

    // Departments match loosely: "MIS" and "mis " are one team.
    private function teamKey(?string $team): ?string
    {
        $key = strtolower(trim((string) $team));

        return $key === '' ? null : $key;
    }

    private function remainingCents(int $envelopeId): int
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
