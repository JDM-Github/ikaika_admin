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
 * as the receipts migrated from Airtable are.
 *
 * Edit transactions (2026-10-13, user) changes a transaction's name, date, payor or payee, notes,
 * receipt and amount. Its type, envelope and account stay. A new amount moves its envelope by the
 * difference -- past what is left only once confirmed, as adding does -- and a transfer it left pending
 * owes the new amount; one already completed keeps the amount where it is. A line a transfer, an
 * allocation, funds received or an approval wrote is changed where it was made, since the ledger reads
 * those by their own names.
 */
final class PortalBudgetTransactions
{
    public const INCOME = 'Income';

    public const EXPENSE = 'Expense';

    public const RECEIPT_TABLE = 'transactions';

    public const RECEIPT_FIELD = 'receipt';

    private const RECEIPT_FOLDER = 'ikaika-portal/transactions';

    private const RECEIPT_CACHE_TTL_SECONDS = 3600;

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
        // Every transaction goes through an envelope (2026-10-09); an account is funded from Accounts instead.
        if ($envelopeId === null) {
            abort(422, 'Choose the envelope this transaction comes out of or goes into.');
        }
        $receiptKey = $this->receiptKeyFrom($actor, $request);
        $receipt = $receiptKey === null ? null : Cache::get($receiptKey);
        if ($receiptKey !== null && ! is_array($receipt)) {
            abort(422, 'That receipt upload has expired. Choose the photo again.');
        }
        $type = $kind === self::INCOME ? PortalBudgetLedger::TYPE_CREDIT : PortalBudgetLedger::TYPE_EXPENSE;

        [$id, $envelopeName, $pending] = $this->ledger->transaction(function () use ($actor, $type, $name, $cents, $occurredOn, $envelopeId, $accountId, $codeId, $payorPayee, $notes, $isConfirmed, $receipt): array {
            $envelope = $this->ledger->lockEnvelope($envelopeId);
            $account = $accountId === null ? null : $this->ledger->lockAccount($accountId);
            $this->ledger->assertOpenEnvelope($envelope, 'adding a transaction to it');
            // The code chosen first on the form is kept when the envelope is under it or was credited under it
            // (2026-10-21, user); any other code, or none, leaves the line under the one its envelope sits under.
            $codeId = $codeId !== null && in_array($codeId, $this->ledger->codeIdsOf((int) $envelope->id), true)
                ? $codeId
                : $this->ledger->codeIdOf((int) $envelope->id);
            if ($account !== null) {
                $this->ledger->assertOpenAccount($account);
            }
            $this->ledger->assertBudgetCode($codeId);
            $isExpense = $type === PortalBudgetLedger::TYPE_EXPENSE;
            if ($isExpense) {
                $this->ledger->assertCovers($this->ledger->envelopeRemainingCents($envelopeId), $cents, $this->ledger->envelopeName($envelope), $isConfirmed);
            }

            $id = $this->ledger->record($actor, $name, $type, $cents, $occurredOn, $notes, [
                'envelopeId' => $envelopeId,
                'accountId' => $accountId,
                'budgetCodeId' => $codeId,
                'payorPayee' => $payorPayee,
            ]);
            if (is_array($receipt)) {
                $this->ledger->connection()->table('attachments')->insert([
                    'table_name' => self::RECEIPT_TABLE,
                    'record_id' => $id,
                    'field_name' => self::RECEIPT_FIELD,
                    'file_url' => $receipt['fileUrl'],
                    'file_name' => $receipt['fileName'],
                    'file_size_bytes' => $receipt['sizeBytes'],
                    'mime_type' => $receipt['mimeType'],
                ]);
            }

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
        });

        if ($receiptKey !== null) {
            Cache::forget($receiptKey);
        }

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-transactions',
            PortalActivityCopy::addedTransaction($kind, $name, $this->input->peso($cents), $envelopeName),
            (string) $id,
            $request,
            ['type' => $type, 'amount' => $this->input->amount($cents), 'envelopeId' => $envelopeId, 'accountId' => $accountId, 'pendingTransferId' => $pending['id'] ?? null, 'receipt' => is_array($receipt) ? $receipt['fileUrl'] : null],
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
        $receiptKey = $isFundsReceived ? null : $this->receiptKeyFrom($actor, $request);
        $receipt = $receiptKey === null ? null : Cache::get($receiptKey);
        if ($receiptKey !== null && ! is_array($receipt)) {
            abort(422, 'That receipt upload has expired. Choose the photo again.');
        }
        $isRemovingReceipt = ! $isFundsReceived && $request->boolean('removeReceipt');

        $wasCents = $this->ledger->transaction(function () use ($id, $name, $occurredOn, $payorPayee, $notes, $receipt, $isRemovingReceipt, $cents, $isConfirmed, $actor): int {
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
            if (is_array($receipt) || $isRemovingReceipt) {
                $this->ledger->connection()->table('attachments')
                    ->where('table_name', self::RECEIPT_TABLE)
                    ->where('field_name', self::RECEIPT_FIELD)
                    ->where('record_id', $id)
                    ->delete();
            }
            if (is_array($receipt)) {
                $this->ledger->connection()->table('attachments')->insert([
                    'table_name' => self::RECEIPT_TABLE,
                    'record_id' => $id,
                    'field_name' => self::RECEIPT_FIELD,
                    'file_url' => $receipt['fileUrl'],
                    'file_name' => $receipt['fileName'],
                    'file_size_bytes' => $receipt['sizeBytes'],
                    'mime_type' => $receipt['mimeType'],
                ]);
            }
            $trackerParameters = [];
            if (is_array($receipt)) {
                $trackerParameters['receipt'] = $receipt['fileUrl'];
            } elseif ($isRemovingReceipt) {
                $trackerParameters['receipt'] = null;
            }
            $this->ledger->trackerEdit('tracker.transactions', 'transactions', $id, $actor, $trackerParameters);

            return $wasCents;
        });

        if ($receiptKey !== null) {
            Cache::forget($receiptKey);
        }
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
                'receipt' => is_array($receipt) ? $receipt['fileUrl'] : ($isRemovingReceipt ? null : 'kept'),
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

    // The upload a request names, as the uploader's own: another person's receiptId finds nothing.
    private function receiptKeyFrom(Employee $actor, Request $request): ?string
    {
        $receiptId = trim((string) $request->input('receiptId', ''));
        if ($receiptId === '') {
            return null;
        }
        if (! Str::isUuid($receiptId)) {
            abort(422, 'That receipt upload has expired. Choose the photo again.');
        }

        return $this->receiptKey($actor, $receiptId);
    }

    private function receiptKey(Employee $actor, string $receiptId): string
    {
        return 'portal:finance:transaction-receipt:'.$actor->getKey().':'.$receiptId;
    }
}
