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
 */
final class PortalBudgetTransactions
{
    public const INCOME = 'Income';

    public const EXPENSE = 'Expense';

    public const RECEIPT_TABLE = 'transactions';

    public const RECEIPT_FIELD = 'receipt';

    private const RECEIPT_FOLDER = 'ikaika-portal/transactions';

    private const RECEIPT_CACHE_TTL_SECONDS = 3600;

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
            // Whatever code was sent, the line is recorded against the one its envelope sits under.
            $codeId = $this->ledger->codeIdOf((int) $envelope->id);
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
