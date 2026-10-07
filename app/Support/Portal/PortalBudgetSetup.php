<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Adding and changing budget codes and envelopes in the transaction tracker from Finance / Envelopes,
 * and recording the money an envelope receives.
 *
 * Adding only adds a record: a new envelope has nothing credited to it yet, so it opens with nothing
 * left. Its plan amount is the tracker's budget_amount, which is shown but never read as a balance --
 * what an envelope has to spend is every Credit linked to it less every Expense. So money reaches an
 * envelope the way the tracker's own Funds Received rows do: a Credit linked to the envelope, to the
 * budget code it was received against, and to the account holding it.
 *
 * Neither name column is unique in the tracker, but both are read by name: a budget code is how
 * people refer to a transfer, and the ledger names the envelopes a line touches rather than
 * numbering them. So a name already taken is refused, however it is capitalised or spaced. The
 * check runs under a lock inside the insert's transaction, so two admins adding the same name at
 * once do not both get it.
 */
final class PortalBudgetSetup
{
    private const CONNECTION = 'tracker';

    // The tracker's own single-select values, spelled as its Group and Time Period fields spell them.
    public const GROUPS = ['Maintenance', 'Capital Expenditure'];

    public const TIME_PERIODS = ['Monthly', 'One-time'];

    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalAudit $audit,
        private readonly PortalBudgetInput $input,
        private readonly PortalBudgetLedger $ledger,
    ) {}

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function createCode(Employee $actor, Request $request): array
    {
        $this->assertAvailable();
        $name = $this->input->validatedName($request, 'A budget code needs a name.', 'The budget code name');
        $description = $this->input->validatedText($request, 'description', 'The description');
        $receivedOn = $this->input->validatedDate($request, 'receivedOn');
        $expectedCents = $this->input->validatedAmount($request, 'expectedAmount', 'The quotation');

        $id = (int) $this->connection()->transaction(function () use ($name, $description, $receivedOn, $expectedCents): int {
            if ($this->nameIsTaken('budget_codes', 'name', $name)) {
                abort(422, 'A budget code named '.$name.' already exists.');
            }

            return (int) $this->connection()->table('budget_codes')->insertGetId([
                'name' => $name,
                'description' => $description,
                'transfer_date_received' => $receivedOn,
                'expected_amount_quotation' => $this->input->decimal($expectedCents),
            ]);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.budget-codes',
            PortalActivityCopy::addedBudgetCode($name),
            (string) $id,
            $request,
            ['name' => $name, 'receivedOn' => $receivedOn, 'expectedAmount' => $this->input->amount($expectedCents)],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-codes',
            'data' => [
                'id' => (string) $id,
                'name' => $name,
                'description' => $description,
                'receivedOn' => $receivedOn,
                'expectedAmount' => $this->input->amount($expectedCents),
                'envelopes' => [],
            ],
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function createEnvelope(Employee $actor, Request $request): array
    {
        $this->assertAvailable();
        $name = $this->input->validatedName($request, 'An envelope needs a name.', 'The envelope name');
        $group = $this->input->validatedChoice($request, 'group', self::GROUPS, 'Choose Maintenance or Capital Expenditure for the group.');
        $period = $this->input->validatedChoice($request, 'timePeriod', self::TIME_PERIODS, 'Choose Monthly or One-time for the time period.');
        $planCents = $this->input->validatedAmount($request, 'planAmount', 'The plan amount');
        $notes = $this->input->validatedText($request, 'notes', 'The notes');
        $codeId = $this->validatedCodeId($request);
        $ownerId = $this->validatedAccountId($request, 'ownerAccountId');

        [$id, $codeName] = $this->connection()->transaction(function () use ($name, $group, $period, $planCents, $notes, $codeId, $ownerId): array {
            $codeName = null;
            if ($codeId !== null) {
                $code = $this->connection()->table('budget_codes')->where('id', $codeId)->lockForUpdate()->first(['id', 'name']);
                if ($code === null) {
                    abort(422, 'That budget code does not exist.');
                }
                $codeName = $this->input->nameOr($code->name, 'Budget code '.$codeId);
            }
            if ($this->nameIsTaken('envelopes_budget', 'envelope_name', $name)) {
                abort(422, 'An envelope named '.$name.' already exists.');
            }
            $this->assertOwner($ownerId);

            // Open from the start, so it can be charged as soon as money reaches it.
            $id = (int) $this->connection()->table('envelopes_budget')->insertGetId([
                'envelope_name' => $name,
                'budget_amount' => $this->input->decimal($planCents),
                'group_name' => $group,
                'time_period' => $period,
                'active' => true,
                'budget_notes' => $notes,
            ]);
            if ($codeId !== null) {
                $this->connection()->table('envelopes_budget_codes')->insert([
                    'envelope_id' => $id,
                    'budget_code_id' => $codeId,
                ]);
            }
            $this->ledger->setOwner($id, $ownerId);

            return [$id, $codeName];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.budget-envelopes',
            PortalActivityCopy::addedBudgetEnvelope($name, $codeName),
            (string) $id,
            $request,
            ['name' => $name, 'group' => $group, 'timePeriod' => $period, 'planAmount' => $this->input->amount($planCents), 'budgetCodeId' => $codeId, 'ownerAccountId' => $ownerId],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-envelopes',
            'data' => [
                'id' => (string) $id,
                'name' => $name,
                'group' => $group,
                'timePeriod' => $period,
                'planAmount' => $this->input->amount($planCents),
                'funded' => 0.0,
                'spent' => 0.0,
                'remaining' => 0.0,
                'active' => true,
                'budgetCodeId' => $codeId === null ? null : (string) $codeId,
                'ownerAccountId' => $ownerId === null ? null : (string) $ownerId,
            ],
        ];
    }

    /**
     * Changes a budget code. Only the fields sent change, so a code can be renamed without restating
     * its quotation; a field sent blank is cleared. The name stays unique among the other codes.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string}}
     */
    public function updateCode(Employee $actor, int $id, Request $request): array
    {
        $this->assertAvailable();
        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = $this->input->validatedName($request, 'A budget code needs a name.', 'The budget code name');
        }
        if ($request->has('description')) {
            $changes['description'] = $this->input->validatedText($request, 'description', 'The description');
        }
        if ($request->has('receivedOn')) {
            $changes['transfer_date_received'] = $this->input->validatedDate($request, 'receivedOn');
        }
        if ($request->has('expectedAmount')) {
            $changes['expected_amount_quotation'] = $this->input->decimal($this->input->validatedAmount($request, 'expectedAmount', 'The quotation'));
        }

        $name = (string) $this->connection()->transaction(function () use ($id, $changes): string {
            $code = $this->connection()->table('budget_codes')->where('id', $id)->lockForUpdate()->first(['id', 'name']);
            if ($code === null) {
                abort(404, 'That budget code does not exist.');
            }
            if (isset($changes['name']) && $this->isRenamed($code->name, $changes['name']) && $this->nameIsTaken('budget_codes', 'name', $changes['name'], $id)) {
                abort(422, 'A budget code named '.$changes['name'].' already exists.');
            }
            if ($changes !== []) {
                $this->connection()->table('budget_codes')->where('id', $id)->update($changes);
            }

            return $changes['name'] ?? $this->input->nameOr($code->name, 'Budget code '.$id);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'manage.budget-codes',
            PortalActivityCopy::updatedBudgetCode($name),
            (string) $id,
            $request,
            ['changes' => $changes],
        );

        return ['section' => 'manage', 'resource' => 'budget-codes', 'data' => ['id' => (string) $id, 'name' => $name]];
    }

    /**
     * Changes an envelope: its name and details, the budget code it sits under, and whether it is open.
     * Only the fields sent change. A budget code sent replaces every code the envelope sat under, and
     * a null one leaves it under none. Closing takes it out of the review's envelope picker; what was
     * already charged to it stays.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string}}
     */
    public function updateEnvelope(Employee $actor, int $id, Request $request): array
    {
        $this->assertAvailable();
        $changes = [];
        if ($request->has('name')) {
            $changes['envelope_name'] = $this->input->validatedName($request, 'An envelope needs a name.', 'The envelope name');
        }
        if ($request->has('group')) {
            $changes['group_name'] = $this->input->validatedChoice($request, 'group', self::GROUPS, 'Choose Maintenance or Capital Expenditure for the group.');
        }
        if ($request->has('timePeriod')) {
            $changes['time_period'] = $this->input->validatedChoice($request, 'timePeriod', self::TIME_PERIODS, 'Choose Monthly or One-time for the time period.');
        }
        if ($request->has('planAmount')) {
            $changes['budget_amount'] = $this->input->decimal($this->input->validatedAmount($request, 'planAmount', 'The plan amount'));
        }
        if ($request->has('notes')) {
            $changes['budget_notes'] = $this->input->validatedText($request, 'notes', 'The notes');
        }
        if ($request->has('active')) {
            $changes['active'] = $this->input->validatedFlag($request, 'active', 'Say whether the envelope is open with true or false.');
        }
        $relinks = $request->has('budgetCodeId');
        $codeId = $relinks ? $this->validatedCodeId($request) : null;
        $reowns = $request->has('ownerAccountId');
        $ownerId = $reowns ? $this->validatedAccountId($request, 'ownerAccountId') : null;

        [$name, $wasActive] = $this->connection()->transaction(function () use ($id, $changes, $relinks, $codeId, $reowns, $ownerId): array {
            $envelope = $this->connection()->table('envelopes_budget')->where('id', $id)->lockForUpdate()->first(['id', 'envelope_name', 'active']);
            if ($envelope === null) {
                abort(404, 'That budget envelope does not exist.');
            }
            if ($codeId !== null && ! $this->connection()->table('budget_codes')->where('id', $codeId)->lockForUpdate()->exists()) {
                abort(422, 'That budget code does not exist.');
            }
            if (isset($changes['envelope_name']) && $this->isRenamed($envelope->envelope_name, $changes['envelope_name']) && $this->nameIsTaken('envelopes_budget', 'envelope_name', $changes['envelope_name'], $id)) {
                abort(422, 'An envelope named '.$changes['envelope_name'].' already exists.');
            }
            if ($changes !== []) {
                $this->connection()->table('envelopes_budget')->where('id', $id)->update($changes);
            }
            if ($relinks) {
                $this->connection()->table('envelopes_budget_codes')->where('envelope_id', $id)->delete();
                if ($codeId !== null) {
                    $this->connection()->table('envelopes_budget_codes')->insert(['envelope_id' => $id, 'budget_code_id' => $codeId]);
                }
            }
            if ($reowns) {
                $this->assertOwner($ownerId);
                $this->ledger->setOwner($id, $ownerId);
            }

            return [$changes['envelope_name'] ?? $this->input->nameOr($envelope->envelope_name, 'Envelope '.$id), (bool) $envelope->active];
        });

        $isActive = $changes['active'] ?? $wasActive;
        $message = match (true) {
            $wasActive && ! $isActive => PortalActivityCopy::closedBudgetEnvelope($name),
            ! $wasActive && $isActive => PortalActivityCopy::reopenedBudgetEnvelope($name),
            default => PortalActivityCopy::updatedBudgetEnvelope($name),
        };
        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            'manage.budget-envelopes',
            $message,
            (string) $id,
            $request,
            ['changes' => $changes] + ($relinks ? ['budgetCodeId' => $codeId] : []) + ($reowns ? ['ownerAccountId' => $ownerId] : []),
        );

        return ['section' => 'manage', 'resource' => 'budget-envelopes', 'data' => ['id' => (string) $id, 'name' => $name]];
    }

    /**
     * Records money received into an envelope: a Credit, as the tracker's own Funds Received rows
     * are, linked to the envelope and to the budget code and account it came through when those are
     * given. Only an open envelope takes it, and only an open account holds it.
     *
     * @return array{section: string, resource: string, data: array{id: string, name: string, amount: float}}
     */
    public function recordFunds(Employee $actor, int $envelopeId, Request $request): array
    {
        $this->assertAvailable();
        $cents = $this->input->movedAmount($request, 'Enter how much was received.');
        $receivedOn = $this->input->requiredDate($request, 'receivedOn', 'Choose the day the money was received.');
        $codeId = $this->validatedCodeId($request);
        $accountId = $this->validatedAccountId($request);
        $notes = $this->input->validatedText($request, 'notes', 'The notes');

        [$transactionId, $name, $accountId] = $this->ledger->transaction(function () use ($actor, $envelopeId, $cents, $receivedOn, $codeId, $accountId, $notes): array {
            $envelope = $this->ledger->lockEnvelope($envelopeId, 404);
            $name = $this->ledger->envelopeName($envelope);
            $this->ledger->assertOpenEnvelope($envelope, 'recording funds to it');
            $this->ledger->assertBudgetCode($codeId);
            // An owned envelope's money is its owner's: it is held in that account and no other.
            $owner = $this->ledger->ownerOf($envelopeId);
            if ($owner !== null) {
                if ($accountId !== null && $accountId !== (int) $owner->id) {
                    abort(422, $name.' belongs to '.$this->ledger->accountName($owner).', so its funds are held in that account.');
                }
                $accountId = (int) $owner->id;
            }
            if ($accountId !== null) {
                $this->ledger->assertOpenAccount($this->ledger->lockAccount($accountId));
            }

            $transactionId = $this->ledger->record($actor, PortalBudgetLedger::FUNDS_RECEIVED, PortalBudgetLedger::TYPE_CREDIT, $cents, $receivedOn, $notes, [
                'envelopeId' => $envelopeId,
                'budgetCodeId' => $codeId,
                'accountId' => $accountId,
            ]);

            return [$transactionId, $name, $accountId];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-envelopes',
            PortalActivityCopy::recordedEnvelopeFunds($name, $this->input->peso($cents)),
            (string) $envelopeId,
            $request,
            ['transactionId' => $transactionId, 'amount' => $this->input->amount($cents), 'receivedOn' => $receivedOn, 'budgetCodeId' => $codeId, 'accountId' => $accountId],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-envelopes',
            'data' => ['id' => (string) $transactionId, 'name' => $name, 'amount' => (float) $this->input->amount($cents)],
        ];
    }

    private function assertAvailable(): void
    {
        if (! $this->envelopes->isAvailable()) {
            abort(422, 'The transaction tracker is switched off here, so nothing can be added to it.');
        }
    }

    private function validatedCodeId(Request $request): ?int
    {
        return $this->input->validatedId($request, 'budgetCodeId', 'That budget code does not exist.');
    }

    private function validatedAccountId(Request $request, string $key = 'accountId'): ?int
    {
        return $this->input->validatedId($request, $key, 'That account does not exist.');
    }

    // The account an envelope's money belongs to has to be one that is still open.
    private function assertOwner(?int $accountId): void
    {
        if ($accountId !== null) {
            $this->ledger->assertOpenAccount($this->ledger->lockAccount($accountId));
        }
    }

    /*
     * Locks what it reads, so a second insert of the same name waits for this one to commit. A
     * record being renamed does not count against itself.
     */
    private function nameIsTaken(string $table, string $column, string $name, ?int $exceptId = null): bool
    {
        return $this->connection()
            ->table($table)
            ->whereRaw('LOWER(TRIM('.$column.')) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->lockForUpdate()
            ->exists();
    }

    /*
     * Only a new name is checked against the others: the tracker already holds two envelopes of one
     * name, and either can still be recapitalised or changed without being renamed first.
     */
    private function isRenamed(mixed $current, string $name): bool
    {
        return mb_strtolower(trim((string) ($current ?? ''))) !== mb_strtolower($name);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection(self::CONNECTION);
    }
}
