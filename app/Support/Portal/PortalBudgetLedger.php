<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/**
 * Writing the transaction tracker's ledger from Finance: one row per movement of money, linked to the
 * envelope, account and budget code it touched, and read back as balances.
 *
 * A balance is never stored. An envelope holds every Credit linked to it less every Expense, and an
 * account the same over its own links, so a movement is always a row and never an edit of a figure.
 * A transfer is therefore two rows -- an Expense out of one end and a Credit into the other -- named
 * the way the tracker's own transfers are, and each names both ends through the transfer link tables
 * so the pair can be read as one movement.
 *
 * An envelope's owner is the account linked to it in envelopes_accounts, the tracker's own Envelope
 * "Account" field. Only an open account owns: an envelope whose account was closed is nobody's.
 */
final class PortalBudgetLedger
{
    public const TYPE_CREDIT = 'Credit';

    public const TYPE_EXPENSE = 'Expense';

    public const FUNDS_RECEIVED = 'Funds Received';

    public const ACCOUNT_TRANSFER_OUT = 'Expense - Account Transfer';

    public const ACCOUNT_TRANSFER_IN = 'Credit - Account Transfer';

    public const ENVELOPE_TRANSFER_OUT = 'Expense - Envelope Transfer';

    public const ENVELOPE_TRANSFER_IN = 'Credit - Envelope Transfer';

    // Money in an account given to one of its envelopes: out of what is unallocated, into the envelope.
    public const ALLOCATION_OUT = 'Expense - Allocation';

    public const ALLOCATION_IN = 'Credit - Allocation';

    // Both rows of an allocation: money moving inside one account, so neither is received or spent.
    public const ALLOCATIONS = [self::ALLOCATION_OUT, self::ALLOCATION_IN];

    private const CONNECTION = 'tracker';

    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalBudgetInput $input,
    ) {}

    public function assertAvailable(): void
    {
        if (! $this->envelopes->isAvailable()) {
            abort(422, 'The transaction tracker is switched off here, so nothing can be added to it.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function transaction(Closure $work): mixed
    {
        return $this->connection()->transaction($work);
    }

    /**
     * Locks the envelope until the transaction ends, so two people moving its money are serialised and
     * the second reads the balance the first one left.
     */
    public function lockEnvelope(int $id, int $missingStatus = 422): object
    {
        $envelope = $this->connection()->table('envelopes_budget')->where('id', $id)->lockForUpdate()->first(['id', 'envelope_name', 'active']);

        return $envelope ?? abort($missingStatus, 'That budget envelope does not exist.');
    }

    public function lockAccount(int $id, int $missingStatus = 422): object
    {
        $account = $this->connection()->table('accounts')->where('id', $id)->lockForUpdate()->first(['id', 'account_name', 'email', 'active']);

        return $account ?? abort($missingStatus, 'That account does not exist.');
    }

    /**
     * Two records of one table locked in id order, whichever end each is, so two transfers running
     * opposite ways cannot each hold the lock the other waits for.
     *
     * @return array{0: object, 1: object}
     */
    public function lockPair(string $kind, int $sourceId, int $destinationId): array
    {
        $lock = fn (int $id): object => $kind === 'envelope' ? $this->lockEnvelope($id) : $this->lockAccount($id);
        if ($sourceId < $destinationId) {
            $source = $lock($sourceId);

            return [$source, $lock($destinationId)];
        }
        $destination = $lock($destinationId);

        return [$lock($sourceId), $destination];
    }

    public function envelopeName(object $envelope): string
    {
        return $this->input->nameOr($envelope->envelope_name ?? null, 'Envelope '.$envelope->id);
    }

    public function accountName(object $account): string
    {
        return $this->input->nameOr($account->account_name ?? null, 'Account '.$account->id);
    }

    public function assertOpenEnvelope(object $envelope, string $doing): void
    {
        if (! (bool) $envelope->active) {
            abort(422, $this->envelopeName($envelope).' is closed. Reopen it before '.$doing.'.');
        }
    }

    public function assertOpenAccount(object $account): void
    {
        if (! (bool) $account->active) {
            abort(422, $this->accountName($account).' is closed, so it takes no new money.');
        }
    }

    /**
     * Every budget code an envelope may spend under (2026-10-21, user): those it is linked to, and any it
     * was credited under, as Airtable's credits often were before the envelope was linked to a code.
     *
     * @return list<int>
     */
    public function codeIdsOf(int $envelopeId): array
    {
        $linked = $this->connection()->table('envelopes_budget_codes')->where('envelope_id', $envelopeId)->pluck('budget_code_id');
        $credited = $this->connection()
            ->table('transactions_budget_codes as tb')
            ->join('transactions as t', 't.id', '=', 'tb.transaction_id')
            ->join('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->where('te.envelope_id', $envelopeId)
            ->where('t.type', self::TYPE_CREDIT)
            ->pluck('tb.budget_code_id');

        return $linked->merge($credited)->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    // The budget code an envelope sits under -- the first by name when it sits under more -- or null for none.
    public function codeIdOf(int $envelopeId): ?int
    {
        $id = $this->connection()
            ->table('envelopes_budget_codes as ebc')
            ->join('budget_codes as c', 'c.id', '=', 'ebc.budget_code_id')
            ->where('ebc.envelope_id', $envelopeId)
            ->orderBy('c.name')
            ->value('c.id');

        return $id === null ? null : (int) $id;
    }

    public function assertBudgetCode(?int $codeId): void
    {
        if ($codeId !== null && ! $this->connection()->table('budget_codes')->where('id', $codeId)->exists()) {
            abort(422, 'That budget code does not exist.');
        }
    }

    // The open account that owns an envelope, or null for one nobody owns.
    public function ownerOf(int $envelopeId): ?object
    {
        return $this->connection()
            ->table('envelopes_accounts as ea')
            ->join('accounts as a', 'a.id', '=', 'ea.account_id')
            ->where('ea.envelope_id', $envelopeId)
            ->where('a.active', true)
            ->orderBy('a.id')
            ->first(['a.id', 'a.account_name', 'a.email', 'a.active']);
    }

    // Replaces the envelope's owner; null leaves it owned by nobody.
    public function setOwner(int $envelopeId, ?int $accountId): void
    {
        $this->connection()->table('envelopes_accounts')->where('envelope_id', $envelopeId)->delete();
        if ($accountId !== null) {
            $this->connection()->table('envelopes_accounts')->insert(['envelope_id' => $envelopeId, 'account_id' => $accountId]);
        }
    }

    public function envelopeRemainingCents(int $envelopeId): int
    {
        return $this->envelopes->remainingCents($envelopeId);
    }

    public function accountRemainingCents(int $accountId): int
    {
        $row = $this->connection()
            ->table('transactions_accounts as ta')
            ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
            ->where('ta.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS received', [self::TYPE_CREDIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [self::TYPE_EXPENSE])
            ->first();

        return $this->input->cents($row->received ?? 0) - $this->input->cents($row->spent ?? 0);
    }

    /**
     * What an account holds that no envelope has been given (2026-10-08): its rows tied to no envelope.
     * The rest of its balance is allocated, in the envelopes its rows name.
     */
    public function accountUnallocatedCents(int $accountId): int
    {
        $row = $this->connection()
            ->table('transactions_accounts as ta')
            ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
            ->where('ta.account_id', $accountId)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('transactions_envelopes as te')->whereColumn('te.transaction_id', 't.id'))
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS received', [self::TYPE_CREDIT])
            ->selectRaw('COALESCE(SUM(CASE WHEN t.type = ? THEN t.amount END), 0) AS spent', [self::TYPE_EXPENSE])
            ->first();

        return $this->input->cents($row->received ?? 0) - $this->input->cents($row->spent ?? 0);
    }

    /**
     * Gives an envelope money its account already holds (2026-10-08): an Expense out of the account's
     * unallocated money and a Credit into the envelope, both in the same account, so its balance stays.
     * More than is unallocated only once that is confirmed.
     *
     * @return list<int>
     */
    public function allocate(Employee $actor, int $accountId, int $envelopeId, ?int $codeId, int $cents, string $date, ?string $notes, bool $isConfirmed): array
    {
        return $this->transaction(function () use ($actor, $accountId, $envelopeId, $codeId, $cents, $date, $notes, $isConfirmed): array {
            $account = $this->lockAccount($accountId);
            $this->assertOpenAccount($account);
            $unallocated = $this->accountUnallocatedCents($accountId);
            if ($cents > $unallocated && ! $isConfirmed) {
                abort(422, $this->accountName($account).' has only '.$this->input->peso($unallocated).' unallocated. Confirm to go past it.');
            }

            return [
                $this->record($actor, self::ALLOCATION_OUT, self::TYPE_EXPENSE, $cents, $date, $notes, ['accountId' => $accountId]),
                $this->record($actor, self::ALLOCATION_IN, self::TYPE_CREDIT, $cents, $date, $notes, [
                    'envelopeId' => $envelopeId,
                    'budgetCodeId' => $codeId,
                    'accountId' => $accountId,
                ]),
            ];
        });
    }

    /**
     * Refuses to take more than an envelope or account holds, unless the person was shown the
     * shortfall and confirmed it.
     */
    public function assertCovers(int $remainingCents, int $cents, string $name, bool $isConfirmed): void
    {
        if ($cents > $remainingCents && ! $isConfirmed) {
            abort(422, $name.' has only '.$this->input->peso($remainingCents).' left. Confirm to go past it.');
        }
    }

    // The open account a person holds, matched on email the way the team accounts are.
    public function accountHeldBy(Employee $employee): ?object
    {
        $email = strtolower(trim((string) ($employee->email ?? '')));
        if ($email === '') {
            return null;
        }

        return $this->connection()
            ->table('accounts')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('active', true)
            ->orderBy('id')
            ->first(['id', 'account_name', 'email', 'active']);
    }

    /**
     * The note a row was written with, as the person wrote it. Rows the portal wrote before 2026-10-07
     * also end with "Recorded in the Ikaika portal by ...", which created_by already says; it is left off.
     */
    public static function noteOf(mixed $description): ?string
    {
        $note = trim((string) preg_replace('/\s*Recorded in the Ikaika portal by .*\.$/su', '', (string) ($description ?? '')));

        return $note === '' ? null : $note;
    }

    /**
     * Writes one ledger row and its links, its description only the note it was given: who recorded it
     * is created_by's to say.
     *
     * @param  array{envelopeId?: ?int, accountId?: ?int, budgetCodeId?: ?int, transferSourceId?: ?int, transferDestinationId?: ?int, payorPayee?: ?string, sourceKey?: ?string}  $links
     */
    public function record(Employee $actor, string $name, string $type, int $cents, string $date, ?string $notes, array $links = []): int
    {
        $actorName = PortalActivityCopy::displayName($actor);
        $now = now('UTC')->format('Y-m-d H:i:s');
        $id = (int) $this->connection()->table('transactions')->insertGetId([
            'transaction_name' => $name,
            'transaction_date' => $date.' 00:00:00',
            'amount' => $this->input->decimal($cents),
            'type' => $type,
            'payor_payee' => $links['payorPayee'] ?? null,
            'description' => $notes,
            'reviewed_by_email' => is_string($actor->email ?? null) ? $actor->email : null,
            'approval_date' => $now,
            'created_by' => $actorName,
            'created_at' => $now,
            'updated_at' => $now,
            'source_key' => $links['sourceKey'] ?? null,
        ]);
        $joins = [
            'transactions_envelopes' => ['envelope_id', $links['envelopeId'] ?? null],
            'transactions_accounts' => ['account_id', $links['accountId'] ?? null],
            'transactions_budget_codes' => ['budget_code_id', $links['budgetCodeId'] ?? null],
            'transactions_transfer_source_accounts' => ['account_id', $links['transferSourceId'] ?? null],
            'transactions_transfer_destination_accounts' => ['account_id', $links['transferDestinationId'] ?? null],
        ];
        foreach ($joins as $table => [$column, $linkedId]) {
            if ($linkedId !== null) {
                $this->connection()->table($table)->insert(['transaction_id' => $id, $column => $linkedId]);
            }
        }

        return $id;
    }

    /**
     * Money moved from one account to another: an Expense out of the source and a Credit into the
     * destination, both naming the two ends.
     *
     * @return array{0: int, 1: int}
     */
    public function transferBetweenAccounts(Employee $actor, int $sourceId, int $destinationId, int $cents, string $date, ?string $notes): array
    {
        $ends = ['transferSourceId' => $sourceId, 'transferDestinationId' => $destinationId];

        return [
            $this->record($actor, self::ACCOUNT_TRANSFER_OUT, self::TYPE_EXPENSE, $cents, $date, $notes, $ends + ['accountId' => $sourceId]),
            $this->record($actor, self::ACCOUNT_TRANSFER_IN, self::TYPE_CREDIT, $cents, $date, $notes, $ends + ['accountId' => $destinationId]),
        ];
    }

    public function connection(): Connection
    {
        return Model::getConnectionResolver()->connection(self::CONNECTION);
    }
}
