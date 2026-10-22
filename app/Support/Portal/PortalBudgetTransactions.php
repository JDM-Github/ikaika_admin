<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Adding a transaction from Finance / Transactions: income, credited to an envelope or an account, or
 * an expense taken out of them.
 *
 * An expense names the account that paid it and the envelope it was spent from, and those can belong
 * to two people: Nat pays for transport out of Ben's Transportation envelope. It still goes through --
 * Ben's envelope is spent -- but Nat's account paid, so a transfer is left pending from Ben's account
 * to Nat's for the same amount, for someone to complete in Finance / Accounts.
 *
 * A receipt photo is optional (2026-10-07). It is uploaded first, held for an hour under the uploader,
 * and named by receiptId when the transaction is added; it is kept in the tracker's attachments table
 * as the receipts migrated from Airtable are. Several can go with one transaction (2026-10-22, user),
 * named by receiptIds; an edit adds new ones and takes off the ones removedReceiptIds names.
 *
 * Edit transactions (2026-10-13, user) changes a transaction's name, date, payor or payee, notes,
 * receipt and amount. Its type, envelope and account stay. A new amount moves its envelope by the
 * difference -- past what is left only once confirmed, as adding does -- and a transfer it left pending
 * owes the new amount; one already completed keeps the amount where it is. A line a transfer, an
 * allocation, funds received or an approval wrote is changed where it was made, since the ledger reads
 * those by their own names.
 *
 * A split (2026-10-22, user) spreads one transaction over several envelopes, each with its own share and
 * budget code. An envelope link carries no amount, so each share is a line of its own, linked to its
 * envelope; the lines share the source key portal:split:{group}:{n}, so the ledger can show them as one.
 * The shares must add up to the amount, each is checked against its own envelope, and each is edited
 * later on its own.
 */
final class PortalBudgetTransactions
{
    public const INCOME = 'Income';

    public const EXPENSE = 'Expense';

    public const RECEIPT_TABLE = 'transactions';

    public const RECEIPT_FIELD = 'receipt';

    private const RECEIPT_FOLDER = 'ikaika-portal/transactions';

    private const RECEIPT_CACHE_TTL_SECONDS = 3600;

    private const MAX_RECEIPTS = 10;

    /*
     * The lines the ledger writes as linked pairs, read back by these names; Edit leaves them alone. Funds
     * Received is one line into one account, so it is edited like a line entered by hand, its name kept
     * (2026-10-21, user).
     */
    private const SYSTEM_NAMES = [
        PortalBudgetLedger::ACCOUNT_TRANSFER_OUT,
        PortalBudgetLedger::ACCOUNT_TRANSFER_IN,
        PortalBudgetLedger::ENVELOPE_TRANSFER_OUT,
        PortalBudgetLedger::ENVELOPE_TRANSFER_IN,
        PortalBudgetLedger::ALLOCATION_OUT,
        PortalBudgetLedger::ALLOCATION_IN,
    ];

    // A reimbursement approval charges the tracker under this source key; its line is changed on the request.
    private const CHARGE_SOURCE_PREFIX = 'portal:reimbursement-claim:';

    // Each share of a split carries portal:split:{group}:{n}: unique per line, and the group ties them.
    public const SPLIT_SOURCE_PREFIX = 'portal:split:';

    private const MAX_SPLIT_PARTS = 10;

    public function __construct(
        private readonly PortalBudgetLedger $ledger,
        private readonly PortalBudgetTransfers $transfers,
        private readonly PortalBudgetInput $input,
        private readonly PortalAudit $audit,
        private readonly PortalCloudinary $cloudinary,
    ) {}

    /**
     * A receipt photo for a transaction about to be added: the same kinds and size a reimbursement
     * receipt may be.
     *
     * @return array{section: string, resource: string, data: array{receiptId: string, fileName: string, fileUrl: string, mimeType: string, sizeBytes: int, thumbUrl: ?string}}
     */
    public function storeReceipt(Employee $actor, Request $request): array
    {
        $this->ledger->assertAvailable();
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'Choose an image or PDF receipt.');
        }
        if ($file->getSize() > PortalReimbursementRequests::MAX_RECEIPT_BYTES) {
            abort(422, 'A receipt cannot be larger than 8 MB.');
        }
        $mime = $file->getMimeType();
        if (! is_string($mime) || ! in_array($mime, PortalReimbursementRequests::RECEIPT_MIMES, true)) {
            abort(422, 'A receipt has to be a JPEG, PNG, WebP, GIF or PDF.');
        }

        $stored = $this->cloudinary->uploadReceipt($file, self::RECEIPT_FOLDER);
        $receiptId = (string) Str::uuid();
        Cache::put($this->receiptKey($actor, $receiptId), $stored, self::RECEIPT_CACHE_TTL_SECONDS);

        return [
            'section' => 'manage',
            'resource' => 'budget-transactions',
            'data' => [
                'receiptId' => $receiptId,
                'fileName' => $stored['fileName'],
                'fileUrl' => $stored['fileUrl'],
                'mimeType' => $stored['mimeType'],
                'sizeBytes' => $stored['sizeBytes'],
                'thumbUrl' => $this->cloudinary->thumbUrl($stored['fileUrl'], $stored['mimeType']),
            ],
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array{id: string, name: string, type: string, amount: float, pendingTransfer: array{id: string, sourceName: string, destinationName: string, amount: float}|null}}
     */
    public function add(Employee $actor, Request $request): array
    {
        $this->ledger->assertAvailable();
        $kind = $this->input->validatedChoice($request, 'type', [self::INCOME, self::EXPENSE], 'Choose Income or Expense for the type.')
            ?? abort(422, 'Choose Income or Expense for the type.');
        $name = $this->input->validatedName($request, 'A transaction needs a name.', 'The transaction name');
        $cents = $this->input->movedAmount($request, 'Enter the amount.');
        $occurredOn = $this->input->requiredDate($request, 'occurredOn', 'Choose the day of the transaction.');
        $envelopeId = $this->input->validatedId($request, 'envelopeId', 'That budget envelope does not exist.');
        $accountId = $this->input->validatedId($request, 'accountId', 'That account does not exist.');
        $codeId = $this->input->validatedId($request, 'budgetCodeId', 'That budget code does not exist.');
        $payorPayee = $this->input->validatedText($request, 'payorPayee', 'The payor or payee', PortalBudgetInput::MAX_NAME);
        $notes = $this->input->validatedText($request, 'notes', 'The notes');
        $isConfirmed = $this->input->confirmed($request, 'allowOverspend');
        $splits = $this->validatedSplits($request);
        // Every transaction goes through an envelope (2026-10-09); an account is funded from Accounts instead.
        if ($splits === [] && $envelopeId === null) {
            abort(422, 'Choose the envelope this transaction comes out of or goes into.');
        }
        $shared = array_sum(array_column($splits, 'cents'));
        if ($splits !== [] && $shared !== $cents) {
            abort(422, 'The split adds up to '.$this->input->peso($shared).', but the amount is '.$this->input->peso($cents).'. Make the shares match it.');
        }
        $parts = $splits !== [] ? $splits : [['envelopeId' => (int) $envelopeId, 'codeId' => $codeId, 'cents' => $cents]];
        $group = $splits !== [] ? (string) Str::uuid() : null;
        $receiptKeys = $this->receiptKeysFrom($actor, $request);
        $receipts = $this->heldReceipts($receiptKeys);
        $type = $kind === self::INCOME ? PortalBudgetLedger::TYPE_CREDIT : PortalBudgetLedger::TYPE_EXPENSE;

        [$ids, $envelopeNames, $owed] = $this->ledger->transaction(function () use ($actor, $type, $name, $parts, $group, $occurredOn, $accountId, $payorPayee, $notes, $isConfirmed, $receipts): array {
            $account = $accountId === null ? null : $this->ledger->lockAccount($accountId);
            if ($account !== null) {
                $this->ledger->assertOpenAccount($account);
            }
            $ids = [];
            $names = [];
            $owed = [];
            foreach ($parts as $index => $part) {
                [$id, $envelopeName, $pending] = $this->recordLine($actor, $type, $name, $part, $occurredOn, $account, [
                    'payorPayee' => $payorPayee,
                    'notes' => $notes,
                    'isConfirmed' => $isConfirmed,
                    'receipts' => $receipts,
                    'sourceKey' => $group === null ? null : self::SPLIT_SOURCE_PREFIX.$group.':'.($index + 1),
                ]);
                $ids[] = $id;
                $names[] = $envelopeName;
                if ($pending !== null) {
                    $owed[] = $pending;
                }
            }

            return [$ids, $names, $owed];
        });
        $id = $ids[0];
        $pending = $owed[0] ?? null;

        $this->forgetReceipts($receiptKeys);

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-transactions',
            PortalActivityCopy::addedTransaction($kind, $name, $this->input->peso($cents), implode(', ', $envelopeNames)),
            (string) $id,
            $request,
            [
                'type' => $type,
                'amount' => $this->input->amount($cents),
                'envelopeId' => $parts[0]['envelopeId'],
                'accountId' => $accountId,
                'pendingTransferId' => $pending['id'] ?? null,
                'receipts' => array_column($receipts, 'fileUrl'),
                'split' => $group === null ? null : array_map(fn (array $part, int $lineId): array => [
                    'transactionId' => $lineId,
                    'envelopeId' => $part['envelopeId'],
                    'amount' => $this->input->amount($part['cents']),
                ], $parts, $ids),
                'pendingTransferIds' => array_column($owed, 'id'),
            ],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-transactions',
            'data' => [
                'id' => (string) $id,
                'name' => $name,
                'type' => $type,
                'amount' => (float) $this->input->amount($cents),
                'pendingTransfer' => $pending,
            ],
        ];
    }

    /**
     * One line of a transaction, or one share of a split, through its envelope: the code kept, the envelope
     * checked, the row written, and a transfer left owed when the envelope's money is another account's.
     *
     * @param  array{envelopeId: int, codeId: ?int, cents: int}  $part
     * @param  array{payorPayee: ?string, notes: ?string, isConfirmed: bool, receipts: list<array<string, mixed>>, sourceKey: ?string}  $details
     * @return array{0: int, 1: string, 2: array{id: string, sourceName: string, destinationName: string, amount: float}|null}
     */
    private function recordLine(Employee $actor, string $type, string $name, array $part, string $occurredOn, ?object $account, array $details): array
    {
        $envelopeId = $part['envelopeId'];
        $cents = $part['cents'];
        $envelope = $this->ledger->lockEnvelope($envelopeId);
        $this->ledger->assertOpenEnvelope($envelope, 'adding a transaction to it');
        // The code chosen first on the form is kept when the envelope is under it or was credited under it
        // (2026-10-21, user); any other code, or none, leaves the line under the one its envelope sits under.
        $codeId = $part['codeId'] !== null && in_array($part['codeId'], $this->ledger->codeIdsOf((int) $envelope->id), true)
            ? $part['codeId']
            : $this->ledger->codeIdOf((int) $envelope->id);
        $this->ledger->assertBudgetCode($codeId);
        $isExpense = $type === PortalBudgetLedger::TYPE_EXPENSE;
        if ($isExpense) {
            $this->ledger->assertCovers($this->ledger->envelopeRemainingCents($envelopeId), $cents, $this->ledger->envelopeName($envelope), $details['isConfirmed']);
        }

        $accountId = $account === null ? null : (int) $account->id;
        $id = $this->ledger->record($actor, $name, $type, $cents, $occurredOn, $details['notes'], [
            'envelopeId' => $envelopeId,
            'accountId' => $accountId,
            'budgetCodeId' => $codeId,
            'payorPayee' => $details['payorPayee'],
            'sourceKey' => $details['sourceKey'],
        ]);
        $this->attachReceipts($id, $details['receipts']);

        $owner = $isExpense && $account !== null ? $this->ledger->ownerOf($envelopeId) : null;
        $pending = null;
        if ($owner !== null && (int) $owner->id !== $accountId) {
            $reason = 'Cross-account envelope expense on '.$this->ledger->envelopeName($envelope).': '.$name;
            $pending = [
                'id' => (string) $this->transfers->owe($actor, (int) $owner->id, (int) $accountId, $cents, $reason, $id),
                'sourceName' => $this->ledger->accountName($owner),
                'destinationName' => $this->ledger->accountName($account),
                'amount' => (float) $this->input->amount($cents),
            ];
        }

        return [$id, $this->ledger->envelopeName($envelope), $pending];
    }

    /**
     * The shares of a split, each an envelope, an optional budget code and its amount; none when the line
     * is not split. Two to ten envelopes, each once.
     *
     * @return list<array{envelopeId: int, codeId: ?int, cents: int}>
     */
    private function validatedSplits(Request $request): array
    {
        $raw = $request->input('splits');
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            abort(422, 'The split is not in the right shape.');
        }
        if (count($raw) < 2) {
            abort(422, 'A split needs at least two envelopes.');
        }
        if (count($raw) > self::MAX_SPLIT_PARTS) {
            abort(422, 'A split can cover at most '.self::MAX_SPLIT_PARTS.' envelopes.');
        }

        $parts = [];
        foreach ($raw as $share) {
            if (! is_array($share)) {
                abort(422, 'The split is not in the right shape.');
            }
            $line = new Request($share);
            $envelopeId = $this->input->validatedId($line, 'envelopeId', 'That budget envelope does not exist.')
                ?? abort(422, 'Choose the envelope for each share of the split.');
            if (in_array($envelopeId, array_column($parts, 'envelopeId'), true)) {
                abort(422, 'Each envelope can take only one share of a split.');
            }
            $parts[] = [
                'envelopeId' => $envelopeId,
                'codeId' => $this->input->validatedId($line, 'budgetCodeId', 'That budget code does not exist.'),
                'cents' => $this->input->movedAmount($line, 'Enter each envelope\'s share of the split.'),
            ];
        }

        return $parts;
    }

    // The split a line is a share of, by its source key; null for a line that is not split.
    public static function splitKeyOf(mixed $sourceKey): ?string
    {
        $key = (string) ($sourceKey ?? '');
        if (! str_starts_with($key, self::SPLIT_SOURCE_PREFIX)) {
            return null;
        }
        $group = strstr(substr($key, strlen(self::SPLIT_SOURCE_PREFIX)), ':', true);

        return is_string($group) && $group !== '' ? $group : null;
    }

    // Whether Edit may change a line: one entered by hand or funds received, not a pair or an approval's charge.
    public static function isEditable(mixed $name, mixed $sourceKey): bool
    {
        return ! in_array((string) $name, self::SYSTEM_NAMES, true)
            && ! str_starts_with((string) ($sourceKey ?? ''), self::CHARGE_SOURCE_PREFIX);
    }

    // Funds Received is read back by its name, and an edit changes only its amount and day (2026-10-21, user).
    public static function isFundsReceived(mixed $name): bool
    {
        return (string) $name === PortalBudgetLedger::FUNDS_RECEIVED;
    }

    /**
     * A transaction's details changed: name, date, payor or payee, notes, its receipt replaced or taken
     * off, and its amount. What it moved through is never touched.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string}}
     */
    public function edit(Employee $actor, int $id, Request $request): array
    {
        $this->ledger->assertAvailable();
        $row = $this->ledger->connection()->table('transactions')->where('id', $id)->first(['id', 'transaction_name', 'source_key', 'payor_payee', 'description']);
        if ($row === null) {
            abort(404, 'That transaction was not found.');
        }
        if (! self::isEditable($row->transaction_name, $row->source_key)) {
            abort(422, 'This line was written by a transfer, an allocation or an approval. Change it where it was made.');
        }
        $isFundsReceived = self::isFundsReceived($row->transaction_name);
        $name = $isFundsReceived
            ? PortalBudgetLedger::FUNDS_RECEIVED
            : $this->input->validatedName($request, 'A transaction needs a name.', 'The transaction name');
        $occurredOn = $this->input->requiredDate($request, 'occurredOn', 'Choose the day of the transaction.');
        $payorPayee = $isFundsReceived
            ? (is_string($row->payor_payee) ? $row->payor_payee : null)
            : $this->input->validatedText($request, 'payorPayee', 'The payor or payee', PortalBudgetInput::MAX_NAME);
        $notes = $isFundsReceived
            ? (is_string($row->description) ? $row->description : null)
            : $this->input->validatedText($request, 'notes', 'The notes');
        // Left out, the amount stays as it is.
        $cents = $this->input->validatedAmount($request, 'amount', 'The amount');
        if ($cents === 0) {
            abort(422, 'The amount must be more than zero.');
        }
        $isConfirmed = $this->input->confirmed($request, 'allowOverspend');
        $receiptKeys = $isFundsReceived ? [] : $this->receiptKeysFrom($actor, $request);
        $receipts = $this->heldReceipts($receiptKeys);
        // removeReceipt, or the one receiptId an older portal sends, still replaces every receipt the line had.
        $isRemovingReceipt = ! $isFundsReceived && ($request->boolean('removeReceipt') || $request->filled('receiptId'));
        $removedIds = $isFundsReceived ? [] : $this->removedReceiptIds($request);

        $wasCents = $this->ledger->transaction(function () use ($id, $name, $occurredOn, $payorPayee, $notes, $receipts, $isRemovingReceipt, $removedIds, $cents, $isConfirmed): int {
            $before = $this->ledger->connection()->table('transactions')->where('id', $id)->lockForUpdate()->first(['amount', 'type']);
            $wasCents = $this->input->cents($before?->amount);
            if ($cents !== null && $cents !== $wasCents) {
                $this->changeAmount($id, (string) $before?->type, $wasCents, $cents, $isConfirmed);
            }
            $this->ledger->connection()->table('transactions')->where('id', $id)->update([
                'transaction_name' => $name,
                'transaction_date' => $occurredOn.' 00:00:00',
                'payor_payee' => $payorPayee,
                'description' => $notes,
                'updated_at' => now('UTC')->format('Y-m-d H:i:s'),
            ]);
            $attached = $this->ledger->connection()->table('attachments')
                ->where('table_name', self::RECEIPT_TABLE)
                ->where('field_name', self::RECEIPT_FIELD)
                ->where('record_id', $id);
            if ($isRemovingReceipt) {
                $attached->delete();
            } elseif ($removedIds !== []) {
                $attached->whereIn('id', $removedIds)->delete();
            }
            $this->attachReceipts($id, $receipts);

            return $wasCents;
        });

        $this->forgetReceipts($receiptKeys);
        $amountCents = $cents ?? $wasCents;
        $isAmountChanged = $amountCents !== $wasCents;

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'manage.budget-transactions',
            PortalActivityCopy::editedTransaction(
                (string) $row->transaction_name,
                $name,
                $isAmountChanged ? $this->input->peso($wasCents) : null,
                $isAmountChanged ? $this->input->peso($amountCents) : null,
            ),
            (string) $id,
            $request,
            [
                'occurredOn' => $occurredOn,
                'amount' => $this->input->amount($amountCents),
                'previousAmount' => $isAmountChanged ? $this->input->amount($wasCents) : null,
                'receiptsAdded' => array_column($receipts, 'fileUrl'),
                'receiptsRemoved' => $isRemovingReceipt ? 'all' : $removedIds,
            ],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-transactions',
            'data' => ['id' => (string) $id, 'name' => $name, 'amount' => (float) $this->input->amount($amountCents)],
        ];
    }

    /*
     * A new amount on a line: its envelope moves by the difference, and a raise of an expense or a cut
     * of income takes that difference out of what the envelope has left, so it is confirmed past it. A
     * transfer the expense left pending owes the new amount; one already completed has paid the old one.
     */
    private function changeAmount(int $id, string $type, int $wasCents, int $cents, bool $isConfirmed): void
    {
        $envelopeId = $this->ledger->connection()->table('transactions_envelopes')->where('transaction_id', $id)->value('envelope_id');
        if ($envelopeId !== null) {
            $envelope = $this->ledger->lockEnvelope((int) $envelopeId);
            $this->ledger->assertOpenEnvelope($envelope, 'changing an amount in it');
            $taken = $type === PortalBudgetLedger::TYPE_EXPENSE ? $cents - $wasCents : $wasCents - $cents;
            if ($taken > 0) {
                $this->ledger->assertCovers($this->ledger->envelopeRemainingCents((int) $envelopeId), $taken, $this->ledger->envelopeName($envelope), $isConfirmed);
            }
        }

        // Money received into an account cannot come down past what the account has not yet put into envelopes.
        $accountId = $this->ledger->connection()->table('transactions_accounts')->where('transaction_id', $id)->value('account_id');
        if ($envelopeId === null && $accountId !== null && $type === PortalBudgetLedger::TYPE_CREDIT && $cents < $wasCents) {
            $this->ledger->lockAccount((int) $accountId);
            $free = $this->ledger->accountUnallocatedCents((int) $accountId);
            if ($wasCents - $cents > $free) {
                abort(422, 'This account has only '.$this->input->peso(max(0, $free)).' not yet allocated, so this can come down by no more than that. Move money back from its envelopes first.');
            }
        }

        $transfers = $this->ledger->connection()->table(PortalBudgetTransfers::TABLE)->where('origin_transaction_id', $id);
        if ((clone $transfers)->where('status', PortalBudgetTransfers::COMPLETED)->exists()) {
            abort(422, 'The transfer this expense left between accounts is already completed, so its amount stays.');
        }
        (clone $transfers)->where('status', PortalBudgetTransfers::PENDING)->update(['amount' => $this->input->decimal($cents)]);
        $this->ledger->connection()->table('transactions')->where('id', $id)->update(['amount' => $this->input->decimal($cents)]);
    }

    /**
     * The uploads a request names, each as the uploader's own: another person's receiptId finds nothing.
     * receiptIds names several; receiptId, the one an older portal sends, is read beside them.
     *
     * @return list<string>
     */
    private function receiptKeysFrom(Employee $actor, Request $request): array
    {
        $named = $request->input('receiptIds') ?? [];
        if (! is_array($named) || ! array_is_list($named)) {
            abort(422, 'The receipts are not in the right shape.');
        }
        $keys = [];
        foreach ([...$named, $request->input('receiptId')] as $value) {
            $receiptId = trim(is_scalar($value) ? (string) $value : '');
            if ($receiptId === '') {
                continue;
            }
            if (! Str::isUuid($receiptId)) {
                abort(422, 'That receipt upload has expired. Choose the photo again.');
            }
            $keys[$receiptId] = $this->receiptKey($actor, $receiptId);
        }
        if (count($keys) > self::MAX_RECEIPTS) {
            abort(422, 'A transaction can keep up to '.self::MAX_RECEIPTS.' receipts.');
        }

        return array_values($keys);
    }

    /**
     * The uploads held under those keys; one that has expired stops the whole write.
     *
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function heldReceipts(array $keys): array
    {
        return array_map(function (string $key): array {
            $receipt = Cache::get($key);

            return is_array($receipt) ? $receipt : abort(422, 'That receipt upload has expired. Choose the photo again.');
        }, $keys);
    }

    /** @param  list<string>  $keys */
    private function forgetReceipts(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /** @param  list<array<string, mixed>>  $receipts */
    private function attachReceipts(int $id, array $receipts): void
    {
        if ($receipts === []) {
            return;
        }
        $this->ledger->connection()->table('attachments')->insert(array_map(fn (array $receipt): array => [
            'table_name' => self::RECEIPT_TABLE,
            'record_id' => $id,
            'field_name' => self::RECEIPT_FIELD,
            'file_url' => $receipt['fileUrl'],
            'file_name' => $receipt['fileName'],
            'file_size_bytes' => $receipt['sizeBytes'],
            'mime_type' => $receipt['mimeType'],
        ], $receipts));
    }

    /**
     * The receipts an edit takes off, by the attachment ids the ledger sends with them.
     *
     * @return list<int>
     */
    private function removedReceiptIds(Request $request): array
    {
        $named = $request->input('removedReceiptIds') ?? [];
        if (! is_array($named) || ! array_is_list($named)) {
            abort(422, 'The receipts are not in the right shape.');
        }

        return array_values(array_unique(array_map(function (mixed $value): int {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return is_int($id) ? $id : abort(422, 'That receipt was not found.');
        }, $named)));
    }

    private function receiptKey(Employee $actor, string $receiptId): string
    {
        return 'portal:finance:transaction-receipt:'.$actor->getKey().':'.$receiptId;
    }
}
