<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;

/**
 * Adding a transaction from Finance / Transactions: income, credited to an envelope or an account, or
 * an expense taken out of them.
 *
 * An expense names the account that paid it and the envelope it was spent from, and those can belong
 * to two people: Nat pays for transport out of Ben's Transportation envelope. It still goes through --
 * Ben's envelope is spent -- but Nat's account paid, so a transfer is left pending from Ben's account
 * to Nat's for the same amount, for someone to complete in Finance / Accounts.
 */
final class PortalBudgetTransactions
{
    public const INCOME = 'Income';

    public const EXPENSE = 'Expense';

    public function __construct(
        private readonly PortalBudgetLedger $ledger,
        private readonly PortalBudgetTransfers $transfers,
        private readonly PortalBudgetInput $input,
        private readonly PortalAudit $audit,
    ) {}

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
        if ($envelopeId === null && $accountId === null) {
            abort(422, 'Choose the envelope or the account this transaction touches.');
        }
        $type = $kind === self::INCOME ? PortalBudgetLedger::TYPE_CREDIT : PortalBudgetLedger::TYPE_EXPENSE;

        [$id, $envelopeName, $pending] = $this->ledger->transaction(function () use ($actor, $type, $name, $cents, $occurredOn, $envelopeId, $accountId, $codeId, $payorPayee, $notes, $isConfirmed): array {
            $envelope = $envelopeId === null ? null : $this->ledger->lockEnvelope($envelopeId);
            $account = $accountId === null ? null : $this->ledger->lockAccount($accountId);
            if ($envelope !== null) {
                $this->ledger->assertOpenEnvelope($envelope, 'adding a transaction to it');
            }
            if ($account !== null) {
                $this->ledger->assertOpenAccount($account);
            }
            $this->ledger->assertBudgetCode($codeId);
            $isExpense = $type === PortalBudgetLedger::TYPE_EXPENSE;
            if ($isExpense && $envelope !== null) {
                $this->ledger->assertCovers($this->ledger->envelopeRemainingCents($envelopeId), $cents, $this->ledger->envelopeName($envelope), $isConfirmed);
            }

            $id = $this->ledger->record($actor, $name, $type, $cents, $occurredOn, $notes, [
                'envelopeId' => $envelopeId,
                'accountId' => $accountId,
                'budgetCodeId' => $codeId,
                'payorPayee' => $payorPayee,
            ]);

            $owner = $isExpense && $envelope !== null && $account !== null ? $this->ledger->ownerOf($envelopeId) : null;
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

            return [$id, $envelope === null ? null : $this->ledger->envelopeName($envelope), $pending];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-transactions',
            PortalActivityCopy::addedTransaction($kind, $name, $this->input->peso($cents), $envelopeName),
            (string) $id,
            $request,
            ['type' => $type, 'amount' => $this->input->amount($cents), 'envelopeId' => $envelopeId, 'accountId' => $accountId, 'pendingTransferId' => $pending['id'] ?? null],
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
}
