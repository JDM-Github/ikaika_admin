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

    private const MAX_NAME = 255;

    private const MAX_TEXT = 5000;

    // DECIMAL(15, 2): thirteen digits before the point.
    private const MAX_AMOUNT_CENTS = 999_999_999_999_999;

    private const FUNDS_NAME = 'Funds Received';

    private const TYPE_CREDIT = 'Credit';

    public function __construct(
        private readonly PortalBudgetEnvelopes $envelopes,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function createCode(Employee $actor, Request $request): array
    {
        $this->assertAvailable();
        $name = $this->validatedName($request, 'A budget code needs a name.', 'The budget code name');
        $description = $this->validatedText($request, 'description', 'The description');
        $receivedOn = $this->validatedDate($request, 'receivedOn');
        $expectedCents = $this->validatedAmount($request, 'expectedAmount', 'The quotation');

        $id = (int) $this->connection()->transaction(function () use ($name, $description, $receivedOn, $expectedCents): int {
            if ($this->nameIsTaken('budget_codes', 'name', $name)) {
                abort(422, 'A budget code named '.$name.' already exists.');
            }

            return (int) $this->connection()->table('budget_codes')->insertGetId([
                'name' => $name,
                'description' => $description,
                'transfer_date_received' => $receivedOn,
                'expected_amount_quotation' => $this->decimal($expectedCents),
            ]);
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.budget-codes',
            PortalActivityCopy::addedBudgetCode($name),
            (string) $id,
            $request,
            ['name' => $name, 'receivedOn' => $receivedOn, 'expectedAmount' => $this->amount($expectedCents)],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-codes',
            'data' => [
                'id' => (string) $id,
                'name' => $name,
                'description' => $description,
                'receivedOn' => $receivedOn,
                'expectedAmount' => $this->amount($expectedCents),
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
        $name = $this->validatedName($request, 'An envelope needs a name.', 'The envelope name');
        $group = $this->validatedChoice($request, 'group', self::GROUPS, 'Choose Maintenance or Capital Expenditure for the group.');
        $period = $this->validatedChoice($request, 'timePeriod', self::TIME_PERIODS, 'Choose Monthly or One-time for the time period.');
        $planCents = $this->validatedAmount($request, 'planAmount', 'The plan amount');
        $notes = $this->validatedText($request, 'notes', 'The notes');
        $codeId = $this->validatedCodeId($request);

        [$id, $codeName] = $this->connection()->transaction(function () use ($name, $group, $period, $planCents, $notes, $codeId): array {
            $codeName = null;
            if ($codeId !== null) {
                $code = $this->connection()->table('budget_codes')->where('id', $codeId)->lockForUpdate()->first(['id', 'name']);
                if ($code === null) {
                    abort(422, 'That budget code does not exist.');
                }
                $codeName = $this->nameOr($code->name, 'Budget code '.$codeId);
            }
            if ($this->nameIsTaken('envelopes_budget', 'envelope_name', $name)) {
                abort(422, 'An envelope named '.$name.' already exists.');
            }

            // Open from the start, so it can be charged as soon as money reaches it.
            $id = (int) $this->connection()->table('envelopes_budget')->insertGetId([
                'envelope_name' => $name,
                'budget_amount' => $this->decimal($planCents),
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

            return [$id, $codeName];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            'manage.budget-envelopes',
            PortalActivityCopy::addedBudgetEnvelope($name, $codeName),
            (string) $id,
            $request,
            ['name' => $name, 'group' => $group, 'timePeriod' => $period, 'planAmount' => $this->amount($planCents), 'budgetCodeId' => $codeId],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-envelopes',
            'data' => [
                'id' => (string) $id,
                'name' => $name,
                'group' => $group,
                'timePeriod' => $period,
                'planAmount' => $this->amount($planCents),
                'funded' => 0.0,
                'spent' => 0.0,
                'remaining' => 0.0,
                'active' => true,
                'budgetCodeId' => $codeId === null ? null : (string) $codeId,
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
            $changes['name'] = $this->validatedName($request, 'A budget code needs a name.', 'The budget code name');
        }
        if ($request->has('description')) {
            $changes['description'] = $this->validatedText($request, 'description', 'The description');
        }
        if ($request->has('receivedOn')) {
            $changes['transfer_date_received'] = $this->validatedDate($request, 'receivedOn');
        }
        if ($request->has('expectedAmount')) {
            $changes['expected_amount_quotation'] = $this->decimal($this->validatedAmount($request, 'expectedAmount', 'The quotation'));
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

            return $changes['name'] ?? $this->nameOr($code->name, 'Budget code '.$id);
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
            $changes['envelope_name'] = $this->validatedName($request, 'An envelope needs a name.', 'The envelope name');
        }
        if ($request->has('group')) {
            $changes['group_name'] = $this->validatedChoice($request, 'group', self::GROUPS, 'Choose Maintenance or Capital Expenditure for the group.');
        }
        if ($request->has('timePeriod')) {
            $changes['time_period'] = $this->validatedChoice($request, 'timePeriod', self::TIME_PERIODS, 'Choose Monthly or One-time for the time period.');
        }
        if ($request->has('planAmount')) {
            $changes['budget_amount'] = $this->decimal($this->validatedAmount($request, 'planAmount', 'The plan amount'));
        }
        if ($request->has('notes')) {
            $changes['budget_notes'] = $this->validatedText($request, 'notes', 'The notes');
        }
        if ($request->has('active')) {
            $changes['active'] = $this->validatedFlag($request, 'active', 'Say whether the envelope is open with true or false.');
        }
        $relinks = $request->has('budgetCodeId');
        $codeId = $relinks ? $this->validatedCodeId($request) : null;

        [$name, $wasActive] = $this->connection()->transaction(function () use ($id, $changes, $relinks, $codeId): array {
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

            return [$changes['envelope_name'] ?? $this->nameOr($envelope->envelope_name, 'Envelope '.$id), (bool) $envelope->active];
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
            ['changes' => $changes] + ($relinks ? ['budgetCodeId' => $codeId] : []),
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
        $cents = $this->validatedAmount($request, 'amount', 'The amount');
        if ($cents === null) {
            abort(422, 'Enter how much was received.');
        }
        if ($cents === 0) {
            abort(422, 'The amount must be more than zero.');
        }
        $receivedOn = $this->validatedDate($request, 'receivedOn');
        if ($receivedOn === null) {
            abort(422, 'Choose the day the money was received.');
        }
        $codeId = $this->validatedCodeId($request);
        $accountId = $this->validatedAccountId($request);
        $notes = $this->validatedText($request, 'notes', 'The notes');
        $actorName = PortalActivityCopy::displayName($actor);

        [$transactionId, $name] = $this->connection()->transaction(function () use ($envelopeId, $cents, $receivedOn, $codeId, $accountId, $notes, $actor, $actorName): array {
            $envelope = $this->connection()->table('envelopes_budget')->where('id', $envelopeId)->lockForUpdate()->first(['id', 'envelope_name', 'active']);
            if ($envelope === null) {
                abort(404, 'That budget envelope does not exist.');
            }
            $name = $this->nameOr($envelope->envelope_name, 'Envelope '.$envelopeId);
            if (! (bool) $envelope->active) {
                abort(422, $name.' is closed. Reopen it before recording funds to it.');
            }
            if ($codeId !== null && ! $this->connection()->table('budget_codes')->where('id', $codeId)->exists()) {
                abort(422, 'That budget code does not exist.');
            }
            if ($accountId !== null) {
                $account = $this->connection()->table('accounts')->where('id', $accountId)->first(['id', 'account_name', 'active']);
                if ($account === null) {
                    abort(422, 'That account does not exist.');
                }
                if (! (bool) $account->active) {
                    abort(422, $this->nameOr($account->account_name, 'That account').' is closed, so it takes no new money.');
                }
            }

            $now = now('UTC')->format('Y-m-d H:i:s');
            $transactionId = (int) $this->connection()->table('transactions')->insertGetId([
                'transaction_name' => self::FUNDS_NAME,
                'transaction_date' => $receivedOn.' 00:00:00',
                'amount' => $this->decimal($cents),
                'type' => self::TYPE_CREDIT,
                'description' => ($notes === null ? '' : $notes.' ').'Recorded in the Ikaika portal by '.$actorName.'.',
                'reviewed_by_email' => is_string($actor->email ?? null) ? $actor->email : null,
                'approval_date' => $now,
                'created_by' => $actorName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->connection()->table('transactions_envelopes')->insert(['transaction_id' => $transactionId, 'envelope_id' => $envelopeId]);
            if ($codeId !== null) {
                $this->connection()->table('transactions_budget_codes')->insert(['transaction_id' => $transactionId, 'budget_code_id' => $codeId]);
            }
            if ($accountId !== null) {
                $this->connection()->table('transactions_accounts')->insert(['transaction_id' => $transactionId, 'account_id' => $accountId]);
            }

            return [$transactionId, $name];
        });

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            'manage.budget-envelopes',
            PortalActivityCopy::recordedEnvelopeFunds($name, $this->peso($cents)),
            (string) $envelopeId,
            $request,
            ['transactionId' => $transactionId, 'amount' => $this->amount($cents), 'receivedOn' => $receivedOn, 'budgetCodeId' => $codeId, 'accountId' => $accountId],
        );

        return [
            'section' => 'manage',
            'resource' => 'budget-envelopes',
            'data' => ['id' => (string) $transactionId, 'name' => $name, 'amount' => (float) $this->amount($cents)],
        ];
    }

    private function assertAvailable(): void
    {
        if (! $this->envelopes->isAvailable()) {
            abort(422, 'The transaction tracker is switched off here, so nothing can be added to it.');
        }
    }

    private function validatedName(Request $request, string $missing, string $label): string
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            abort(422, $missing);
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            abort(422, $label.' cannot exceed '.self::MAX_NAME.' characters.');
        }

        return $name;
    }

    private function validatedText(Request $request, string $key, string $label): ?string
    {
        $text = trim((string) $request->input($key, ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            abort(422, $label.' cannot exceed '.self::MAX_TEXT.' characters.');
        }

        return $text;
    }

    private function validatedDate(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $value;
    }

    /**
     * An amount in whole centavos, or null when left blank. Pesos arrive as a number or a numeric
     * string; a negative figure, or one past what the column holds, is refused.
     */
    private function validatedAmount(Request $request, string $key, string $label): ?int
    {
        $value = $request->input($key);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_numeric($value)) {
            abort(422, $label.' must be a number.');
        }
        $cents = (int) round(((float) $value) * 100);
        if ($cents < 0) {
            abort(422, $label.' cannot be negative.');
        }
        if ($cents > self::MAX_AMOUNT_CENTS) {
            abort(422, $label.' is too large.');
        }

        return $cents;
    }

    /**
     * @param  list<string>  $choices
     */
    private function validatedChoice(Request $request, string $key, array $choices, string $message): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }
        if (! in_array($value, $choices, true)) {
            abort(422, $message);
        }

        return $value;
    }

    private function validatedCodeId(Request $request): ?int
    {
        return $this->validatedId($request, 'budgetCodeId', 'That budget code does not exist.');
    }

    private function validatedAccountId(Request $request): ?int
    {
        return $this->validatedId($request, 'accountId', 'That account does not exist.');
    }

    private function validatedId(Request $request, string $key, string $message): ?int
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== trim((string) $value)) {
            abort(422, $message);
        }

        return (int) $value;
    }

    private function validatedFlag(Request $request, string $key, string $message): bool
    {
        $flag = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            abort(422, $message);
        }

        return $flag;
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

    private function nameOr(mixed $value, string $fallback): string
    {
        $name = trim((string) ($value ?? ''));

        return $name === '' ? $fallback : $name;
    }

    private function peso(int $cents): string
    {
        return '₱'.number_format($cents / 100, 2);
    }

    private function decimal(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }

    private function amount(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection(self::CONNECTION);
    }
}
