<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;

/**
 * Moving money in the transaction tracker: account to account, envelope to envelope, and the fund
 * transfers left pending between accounts until someone completes them.
 *
 * An envelope transfer follows the money's accounts. An envelope with an owner uses its owner's; one
 * without names the account its side is taken from or held in, when the person chooses one. A side
 * left with no account takes the other side's, so the money stays in that one account: an account
 * only gives or gains when both sides name accounts and they differ. Then the Expense out of the
 * source envelope leaves the first account and the Credit into the destination lands in the second,
 * so the money crosses accounts on its way between envelopes.
 *
 * A pending transfer is owed, not moved: an expense paid from one person's account out of another's
 * envelope leaves one behind, from the envelope owner's account to the account that paid. It moves
 * nothing until it is completed, which writes the account transfer it stood for; cancelling it closes
 * it without moving anything. Either way it stays listed, so the history of what was owed is kept.
 */
final class PortalBudgetTransfers
{
    public const TABLE = 'account_fund_transfers';

    public const PENDING = 'Pending';

    public const COMPLETED = 'Completed';

    public const CANCELLED = 'Cancelled';

    public function __construct(
        private readonly PortalBudgetLedger $ledger,
        private readonly PortalBudgetInput $input,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, data: array{sourceTransactionId: string, destinationTransactionId: string, amount: float}}
     */
    public function betweenAccounts(Employee $actor, Request $request): array
    {
        $this->ledger->assertAvailable();
        [$sourceId, $destinationId] = $this->ends($request, 'sourceAccountId', 'destinationAccountId', 'account');
        $cents = $this->input->movedAmount($request, 'Enter how much to transfer.');
        $transferredOn = $this->input->requiredDate($request, 'transferredOn', 'Choose the day of the transfer.');
        $reason = $this->input->validatedText($request, 'reason', 'The reason');
        $isConfirmed = $this->input->confirmed($request, 'allowOverdraw');

        [$ids, $sourceName, $destinationName] = $this->ledger->transaction(function () use ($actor, $sourceId, $destinationId, $cents, $transferredOn, $reason, $isConfirmed): array {
            [$source, $destination] = $this->ledger->lockPair('account', $sourceId, $destinationId);
            $this->ledger->assertOpenAccount($source);
            $this->ledger->assertOpenAccount($destination);
            $this->ledger->assertCovers($this->ledger->accountRemainingCents($sourceId), $cents, $this->ledger->accountName($source), $isConfirmed);

            return [
                $this->ledger->transferBetweenAccounts($actor, $sourceId, $destinationId, $cents, $transferredOn, $reason),
                $this->ledger->accountName($source),
                $this->ledger->accountName($destination),
            ];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-accounts',
            PortalActivityCopy::transferredFunds($this->input->peso($cents), $sourceName, $destinationName),
            (string) $sourceId,
            $request,
            ['transactionIds' => $ids, 'amount' => $this->input->amount($cents), 'destinationAccountId' => $destinationId],
        );

        return $this->moved('budget-accounts', $ids, $cents);
    }

    /**
     * @return array{section: string, resource: string, data: array{sourceTransactionId: string, destinationTransactionId: string, amount: float, crossesAccounts: bool}}
     */
    public function betweenEnvelopes(Employee $actor, Request $request): array
    {
        $this->ledger->assertAvailable();
        [$sourceId, $destinationId] = $this->ends($request, 'sourceEnvelopeId', 'destinationEnvelopeId', 'envelope');
        $cents = $this->input->movedAmount($request, 'Enter how much to transfer.');
        $transferredOn = $this->input->requiredDate($request, 'transferredOn', 'Choose the day of the transfer.');
        $reason = $this->input->validatedText($request, 'reason', 'The reason');
        $isConfirmed = $this->input->confirmed($request, 'allowOverspend');
        $chosenSource = $this->input->validatedId($request, 'sourceAccountId', 'That account does not exist.');
        $chosenDestination = $this->input->validatedId($request, 'destinationAccountId', 'That account does not exist.');

        [$ids, $sourceName, $destinationName, $crosses] = $this->moveBetweenEnvelopes(
            $actor, $sourceId, $destinationId, $cents, $transferredOn, $reason, $isConfirmed, $chosenSource, $chosenDestination,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-envelopes',
            PortalActivityCopy::transferredFunds($this->input->peso($cents), $sourceName, $destinationName),
            (string) $sourceId,
            $request,
            ['transactionIds' => $ids, 'amount' => $this->input->amount($cents), 'destinationEnvelopeId' => $destinationId, 'crossesAccounts' => $crosses],
        );

        $moved = $this->moved('budget-envelopes', $ids, $cents);
        $moved['data']['crossesAccounts'] = $crosses;

        return $moved;
    }

    /**
     * The envelope transfer itself, its own transaction (or a savepoint inside the caller's): the pair of
     * rows, crossing the two owners' accounts when those differ. Adding an envelope funded from another
     * moves its opening money through here too.
     *
     * @return array{0: list<int>, 1: string, 2: string, 3: bool}
     */
    public function moveBetweenEnvelopes(
        Employee $actor,
        int $sourceId,
        int $destinationId,
        int $cents,
        string $transferredOn,
        ?string $reason,
        bool $isConfirmed,
        ?int $chosenSource = null,
        ?int $chosenDestination = null,
    ): array {
        return $this->ledger->transaction(function () use ($actor, $sourceId, $destinationId, $cents, $transferredOn, $reason, $isConfirmed, $chosenSource, $chosenDestination): array {
            [$source, $destination] = $this->ledger->lockPair('envelope', $sourceId, $destinationId);
            // A closed envelope can still be emptied; only an open one takes money in.
            $this->ledger->assertOpenEnvelope($destination, 'moving money into it');
            $this->ledger->assertCovers($this->ledger->envelopeRemainingCents($sourceId), $cents, $this->ledger->envelopeName($source), $isConfirmed);

            $fromAccount = $this->sideAccount($source, $chosenSource);
            $toAccount = $this->sideAccount($destination, $chosenDestination);
            // One account known: the money stays in it, so no account gives what another does not gain.
            $fromAccount ??= $toAccount;
            $toAccount ??= $fromAccount;
            $crosses = $fromAccount !== null && $toAccount !== null && $fromAccount !== $toAccount;
            $ends = $crosses ? ['transferSourceId' => $fromAccount, 'transferDestinationId' => $toAccount] : [];
            $ids = [
                $this->ledger->record($actor, PortalBudgetLedger::ENVELOPE_TRANSFER_OUT, PortalBudgetLedger::TYPE_EXPENSE, $cents, $transferredOn, $reason, $ends + [
                    'envelopeId' => $sourceId,
                    'accountId' => $fromAccount,
                ]),
                $this->ledger->record($actor, PortalBudgetLedger::ENVELOPE_TRANSFER_IN, PortalBudgetLedger::TYPE_CREDIT, $cents, $transferredOn, $reason, $ends + [
                    'envelopeId' => $destinationId,
                    'accountId' => $toAccount,
                ]),
            ];

            return [$ids, $this->ledger->envelopeName($source), $this->ledger->envelopeName($destination), $crosses];
        });
    }

    /**
     * The account one side of an envelope transfer uses: its owner's, or for an envelope nobody owns,
     * the open account chosen for it, if any. An owned envelope cannot be pointed at another account.
     */
    private function sideAccount(object $envelope, ?int $chosen): ?int
    {
        $owner = $this->ledger->ownerOf((int) $envelope->id);
        if ($owner !== null) {
            if ($chosen !== null && $chosen !== (int) $owner->id) {
                abort(422, $this->ledger->envelopeName($envelope).' belongs to '.$this->ledger->accountName($owner).', so its side of the transfer uses that account.');
            }

            return (int) $owner->id;
        }
        if ($chosen !== null) {
            $this->ledger->assertOpenAccount($this->ledger->lockAccount($chosen));
        }

        return $chosen;
    }

    /**
     * Leaves a transfer owed from one account to another, moving nothing yet.
     */
    public function owe(Employee $actor, int $sourceId, int $destinationId, int $cents, string $reason, int $originTransactionId): int
    {
        return (int) $this->ledger->connection()->table(self::TABLE)->insertGetId([
            'source_account_id' => $sourceId,
            'destination_account_id' => $destinationId,
            'amount' => $this->input->decimal($cents),
            'reason' => mb_substr($reason, 0, PortalBudgetInput::MAX_TEXT),
            'status' => self::PENDING,
            'origin_transaction_id' => $originTransactionId,
            'created_by' => PortalActivityCopy::displayName($actor),
            'created_by_email' => is_string($actor->email ?? null) ? $actor->email : null,
            'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Pays a pending transfer: the account transfer it stood for is written, dated the day sent (today
     * when none is), and the transfer is marked completed with the two rows it wrote.
     *
     * @return array{section: string, resource: string, data: array{sourceTransactionId: string, destinationTransactionId: string, amount: float}}
     */
    public function complete(Employee $actor, int $id, Request $request): array
    {
        $this->ledger->assertAvailable();
        $isConfirmed = $this->input->confirmed($request, 'allowOverdraw');
        $transferredOn = $this->input->validatedDate($request, 'transferredOn') ?? now('UTC')->format('Y-m-d');

        [$ids, $cents, $sourceName, $destinationName] = $this->ledger->transaction(function () use ($actor, $id, $isConfirmed, $transferredOn): array {
            $transfer = $this->lockPending($id);
            if ($transfer->source_account_id === null || $transfer->destination_account_id === null) {
                abort(422, 'One of this transfer\'s accounts no longer exists. Cancel it instead.');
            }
            $sourceId = (int) $transfer->source_account_id;
            $destinationId = (int) $transfer->destination_account_id;
            $cents = $this->input->cents($transfer->amount);
            [$source, $destination] = $this->ledger->lockPair('account', $sourceId, $destinationId);
            $this->ledger->assertOpenAccount($source);
            $this->ledger->assertOpenAccount($destination);
            $this->ledger->assertCovers($this->ledger->accountRemainingCents($sourceId), $cents, $this->ledger->accountName($source), $isConfirmed);

            $ids = $this->ledger->transferBetweenAccounts($actor, $sourceId, $destinationId, $cents, $transferredOn, $this->text($transfer->reason));
            $this->settle($id, $actor, self::COMPLETED, ['source_transaction_id' => $ids[0], 'destination_transaction_id' => $ids[1]]);

            return [$ids, $cents, $this->ledger->accountName($source), $this->ledger->accountName($destination)];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-accounts',
            PortalActivityCopy::completedTransfer($this->input->peso($cents), $sourceName, $destinationName),
            (string) $id,
            $request,
            ['transactionIds' => $ids, 'amount' => $this->input->amount($cents)],
        );

        return $this->moved('budget-accounts', $ids, $cents);
    }

    /**
     * @return array{section: string, resource: string, data: array{id: string, status: string}}
     */
    public function cancel(Employee $actor, int $id, Request $request): array
    {
        $this->ledger->assertAvailable();
        $cents = $this->ledger->transaction(function () use ($actor, $id): int {
            $transfer = $this->lockPending($id);
            $this->settle($id, $actor, self::CANCELLED);

            return $this->input->cents($transfer->amount);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-accounts',
            PortalActivityCopy::cancelledTransfer($this->input->peso($cents)),
            (string) $id,
            $request,
            ['amount' => $this->input->amount($cents)],
        );

        return ['section' => 'manage', 'resource' => 'budget-accounts', 'data' => ['id' => (string) $id, 'status' => self::CANCELLED]];
    }

    /**
     * The two ends of a transfer, which have to be two different records.
     *
     * @return array{0: int, 1: int}
     */
    private function ends(Request $request, string $sourceKey, string $destinationKey, string $noun): array
    {
        $sourceId = $this->input->requiredId($request, $sourceKey, 'Choose the '.$noun.' to transfer from.');
        $destinationId = $this->input->requiredId($request, $destinationKey, 'Choose the '.$noun.' to transfer to.');
        if ($sourceId === $destinationId) {
            abort(422, 'Choose two different '.$noun.'s.');
        }

        return [$sourceId, $destinationId];
    }

    private function lockPending(int $id): object
    {
        $transfer = $this->ledger->connection()->table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
        if ($transfer === null) {
            abort(404, 'That fund transfer does not exist.');
        }
        if ((string) $transfer->status !== self::PENDING) {
            abort(422, 'That transfer is already '.strtolower((string) $transfer->status).'.');
        }

        return $transfer;
    }

    /**
     * @param  array<string, int>  $written
     */
    private function settle(int $id, Employee $actor, string $status, array $written = []): void
    {
        $this->ledger->connection()->table(self::TABLE)->where('id', $id)->update([
            'status' => $status,
            'settled_at' => now('UTC')->format('Y-m-d H:i:s'),
            'settled_by' => PortalActivityCopy::displayName($actor),
            'settled_by_email' => is_string($actor->email ?? null) ? $actor->email : null,
        ] + $written);
    }

    /**
     * @param  array{0: int, 1: int}  $ids
     * @return array{section: string, resource: string, data: array{sourceTransactionId: string, destinationTransactionId: string, amount: float}}
     */
    private function moved(string $resource, array $ids, int $cents): array
    {
        return [
            'section' => 'manage',
            'resource' => $resource,
            'data' => [
                'sourceTransactionId' => (string) $ids[0],
                'destinationTransactionId' => (string) $ids[1],
                'amount' => (float) $this->input->amount($cents),
            ],
        ];
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
