<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adding budget codes and envelopes to the transaction tracker from Finance / Envelopes.
 *
 * Runs against the real tracker database inside a transaction, so the live codes and envelopes are
 * present alongside the ones added here. Names are seeded ones no live record carries.
 */
class PortalBudgetSetupTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'tracker', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        config(['products.catalog.transaction-tracker.enabled' => true]);
    }

    public function test_an_admin_adds_a_budget_code(): void
    {
        $response = $this->addCode([
            'name' => '  SEED-SETUP-001-20310301 ',
            'description' => 'Seeded pantry restock',
            'receivedOn' => '2031-03-01',
            'expectedAmount' => '12500.5',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'SEED-SETUP-001-20310301')
            ->assertJsonPath('data.receivedOn', '2031-03-01')
            ->assertJsonPath('data.envelopes', []);

        $id = (int) $response->json('data.id');
        $row = DB::connection('tracker')->table('budget_codes')->where('id', $id)->first();
        $this->assertSame('SEED-SETUP-001-20310301', $row->name);
        $this->assertSame('Seeded pantry restock', $row->description);
        $this->assertSame('2031-03-01', substr((string) $row->transfer_date_received, 0, 10));
        $this->assertSame('12500.50', $row->expected_amount_quotation);
        $this->assertEqualsWithDelta(12500.50, $response->json('data.expectedAmount'), 0.001);

        // Read straight back by the Envelopes tab, with nothing under it yet.
        $listed = collect($this->getJson('/api/development/portal/manage/budget-codes', $this->admin())->json('data'))
            ->firstWhere('id', (string) $id);
        $this->assertSame('SEED-SETUP-001-20310301', $listed['name']);
        $this->assertSame([], $listed['envelopes']);

        $log = PortalLog::query()->where('resource', 'manage.budget-codes')->where('record_id', (string) $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('INSERT', $log->action);
        $this->assertStringEndsWith('added the budget code SEED-SETUP-001-20310301', (string) $log->message);
    }

    // The reported ask (2026-10-08): a name and a quotation are needed; the rest can follow later.
    public function test_a_budget_code_needs_a_name_and_a_quotation(): void
    {
        $before = $this->codeCount();
        $this->addCode(['name' => 'SEED-SETUP-002-2031ADV', 'expectedAmount' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the quotation.');
        $this->addCode(['name' => 'SEED-SETUP-002-2031ADV', 'expectedAmount' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The quotation must be more than zero.');
        $this->assertSame($before, $this->codeCount());

        $id = (int) $this->addCode(['name' => 'SEED-SETUP-002-2031ADV', 'description' => '  ', 'receivedOn' => '', 'expectedAmount' => '900'])
            ->assertCreated()
            ->assertJsonPath('data.expectedAmount', 900)
            ->json('data.id');

        $row = DB::connection('tracker')->table('budget_codes')->where('id', $id)->first();
        $this->assertNull($row->description);
        $this->assertNull($row->transfer_date_received);
        $this->assertSame('900.00', $row->expected_amount_quotation);
    }

    public function test_a_budget_code_is_refused_without_a_name_or_with_one_already_taken(): void
    {
        DB::connection('tracker')->table('budget_codes')->insert(['name' => 'SEED-SETUP-003-20310401']);
        $before = $this->codeCount();

        $this->addCode(['name' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A budget code needs a name.');
        // However it is capitalised or spaced, it is the same code to the people who quote it.
        $this->addCode(['name' => ' seed-setup-003-20310401 '])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A budget code named seed-setup-003-20310401 already exists.');

        $this->assertSame($before, $this->codeCount());
    }

    public function test_a_budget_code_refuses_a_malformed_date_or_amount(): void
    {
        $before = $this->codeCount();

        $this->addCode(['name' => 'SEED-SETUP-004', 'receivedOn' => '2031-02-30'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dates must use YYYY-MM-DD.');
        $this->addCode(['name' => 'SEED-SETUP-004', 'expectedAmount' => '-1'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The quotation cannot be negative.');
        $this->addCode(['name' => 'SEED-SETUP-004', 'expectedAmount' => 'lots'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The quotation must be a number.');
        $this->addCode(['name' => 'SEED-SETUP-004', 'expectedAmount' => '10000000000000'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The quotation is too large.');

        $this->assertSame($before, $this->codeCount());
    }

    public function test_an_admin_adds_an_envelope_under_a_budget_code(): void
    {
        $code = (int) DB::connection('tracker')->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-005-20310501']);

        $response = $this->addEnvelope([
            'name' => 'Seeded Setup Pantry',
            'group' => 'Maintenance',
            'timePeriod' => 'Monthly',
            'planAmount' => 1500,
            'notes' => 'Until June',
            'budgetCodeId' => (string) $code,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Seeded Setup Pantry')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.budgetCodeId', (string) $code);

        $id = (int) $response->json('data.id');
        $row = DB::connection('tracker')->table('envelopes_budget')->where('id', $id)->first();
        $this->assertSame('Maintenance', $row->group_name);
        $this->assertSame('Monthly', $row->time_period);
        $this->assertSame('1500.00', $row->budget_amount);
        $this->assertSame('Until June', $row->budget_notes);
        $this->assertTrue((bool) $row->active);

        // Listed under its code, with nothing credited: the plan amount is a plan, not a balance.
        $listed = collect($this->getJson('/api/development/portal/manage/budget-codes', $this->admin())->json('data'))
            ->firstWhere('id', (string) $code);
        $envelope = collect($listed['envelopes'])->firstWhere('id', (string) $id);
        $this->assertNotNull($envelope);
        $this->assertEqualsWithDelta(1500.00, $envelope['planAmount'], 0.001);
        $this->assertEqualsWithDelta(0.00, $envelope['remaining'], 0.001);

        // And open to be charged: the review's picker lists it under its code.
        $open = collect($this->getJson('/api/development/portal/manage/budget-envelopes', $this->admin())->json('data'))
            ->firstWhere('id', (string) $id);
        $this->assertSame('SEED-SETUP-005-20310501', $open['codes'][0]['name']);

        $log = PortalLog::query()->where('resource', 'manage.budget-envelopes')->where('record_id', (string) $id)->first();
        $this->assertNotNull($log);
        $this->assertSame('INSERT', $log->action);
        $this->assertStringEndsWith('added the envelope Seeded Setup Pantry under SEED-SETUP-005-20310501', (string) $log->message);
    }

    public function test_an_envelope_with_no_budget_code_is_listed_under_none(): void
    {
        $id = (string) $this->addEnvelope(['name' => 'Seeded Setup Loose', 'budgetCodeId' => null])
            ->assertCreated()
            ->assertJsonPath('data.group', null)
            ->json('data.id');

        $unassigned = collect($this->getJson('/api/development/portal/manage/budget-codes', $this->admin())->json('unassigned'))
            ->pluck('id');
        $this->assertContains($id, $unassigned);
        $this->assertSame(0, DB::connection('tracker')->table('envelopes_budget_codes')->where('envelope_id', (int) $id)->count());
        $log = PortalLog::query()->where('resource', 'manage.budget-envelopes')->where('record_id', $id)->first();
        $this->assertStringEndsWith('added the envelope Seeded Setup Loose with no budget code', (string) $log?->message);
    }

    public function test_an_envelope_refuses_an_unknown_group_period_or_code(): void
    {
        $before = $this->envelopeCount();

        $this->addEnvelope(['name' => 'Seeded Setup Odd', 'group' => 'Snacks'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose Maintenance or Capital Expenditure for the group.');
        $this->addEnvelope(['name' => 'Seeded Setup Odd', 'timePeriod' => 'Weekly'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose Monthly or One-time for the time period.');
        $this->addEnvelope(['name' => 'Seeded Setup Odd', 'budgetCodeId' => '999999999'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That budget code does not exist.');
        $this->addEnvelope(['name' => 'Seeded Setup Odd', 'budgetCodeId' => 'BGT-001'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That budget code does not exist.');
        $this->addEnvelope(['name' => 'Seeded Setup Odd', 'planAmount' => -5])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The plan amount cannot be negative.');

        $this->assertSame($before, $this->envelopeCount());
    }

    // The ledger names the envelopes a line touches, so two of one name would read each other's lines.
    public function test_an_envelope_is_refused_without_a_name_or_with_one_already_taken(): void
    {
        DB::connection('tracker')->table('envelopes_budget')->insert(['envelope_name' => 'Seeded Setup Taken', 'active' => true]);
        $before = $this->envelopeCount();

        $this->addEnvelope(['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An envelope needs a name.');
        $this->addEnvelope(['name' => 'SEEDED SETUP TAKEN'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An envelope named SEEDED SETUP TAKEN already exists.');
        // The reported ask: no new envelope without an owner.
        $this->addEnvelope(['name' => 'Seeded Setup Unowned', 'ownerAccountId' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose the account that owns this envelope.');
        // The reported ask (2026-10-08): nor without a plan, of more than nothing.
        $this->addEnvelope(['name' => 'Seeded Setup Unplanned', 'planAmount' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the plan amount.');
        $this->addEnvelope(['name' => 'Seeded Setup Unplanned', 'planAmount' => '0'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The plan amount must be more than zero.');

        $this->assertSame($before, $this->envelopeCount());
    }

    public function test_only_an_admin_adds_budget_codes_and_envelopes(): void
    {
        $member = ['Authorization' => 'Bearer '.$this->loginToken($this->activeMember())];
        $codes = $this->codeCount();
        $envelopes = $this->envelopeCount();

        $this->postJson('/api/development/portal/manage/budget-codes', ['name' => 'SEED-SETUP-006'], $member)->assertForbidden();
        $this->postJson('/api/development/portal/manage/budget-envelopes', ['name' => 'Seeded Setup Member'], $member)->assertForbidden();

        $this->assertSame($codes, $this->codeCount());
        $this->assertSame($envelopes, $this->envelopeCount());
    }

    public function test_nothing_is_added_with_the_tracker_switched_off(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);
        $message = 'The transaction tracker is switched off here, so nothing can be added to it.';

        $this->addCode(['name' => 'SEED-SETUP-007'])->assertStatus(422)->assertJsonPath('message', $message);
        $this->addEnvelope(['name' => 'Seeded Setup Off'])->assertStatus(422)->assertJsonPath('message', $message);
    }

    // What a code received is what was credited against the code itself, not what its envelopes hold.
    public function test_the_budget_codes_carry_what_was_received_against_each_code(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-010', 'expected_amount_quotation' => 5000]);
        $envelope = $this->seedEnvelope('Seeded Setup Received', notes: 'Until June');
        $credit = (int) $tracker->table('transactions')->insertGetId(['transaction_name' => 'Funds Received', 'amount' => 1000, 'type' => 'Credit']);
        $expense = (int) $tracker->table('transactions')->insertGetId(['transaction_name' => 'Expense', 'amount' => 200, 'type' => 'Expense']);
        $tracker->table('transactions_budget_codes')->insert([
            ['transaction_id' => $credit, 'budget_code_id' => $code],
            ['transaction_id' => $expense, 'budget_code_id' => $code],
        ]);

        $response = $this->getJson('/api/development/portal/manage/budget-codes', $this->admin())->assertOk();

        $listed = collect($response->json('data'))->firstWhere('id', (string) $code);
        $this->assertEqualsWithDelta(1000.00, $listed['received'], 0.001);
        $loose = collect($response->json('unassigned'))->firstWhere('id', (string) $envelope);
        $this->assertSame('Until June', $loose['notes']);
    }

    public function test_an_admin_edits_a_budget_code_field_by_field(): void
    {
        $id = (int) DB::connection('tracker')->table('budget_codes')->insertGetId([
            'name' => 'SEED-SETUP-011',
            'description' => 'Before',
            'transfer_date_received' => '2031-12-23',
            'expected_amount_quotation' => 5000,
        ]);

        // A mistyped date fixed, and the rest left as it was.
        $this->editCode($id, ['name' => 'SEED-SETUP-011-20301223', 'receivedOn' => '2030-12-23'])
            ->assertOk()
            ->assertJsonPath('data.name', 'SEED-SETUP-011-20301223');
        $row = $this->codeRow($id);
        $this->assertSame('2030-12-23', substr((string) $row->transfer_date_received, 0, 10));
        $this->assertSame('Before', $row->description);
        $this->assertSame('5000.00', $row->expected_amount_quotation);

        // A field sent blank is cleared, but a quotation cannot be taken away.
        $this->editCode($id, ['expectedAmount' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the quotation.');
        $this->editCode($id, ['description' => '', 'expectedAmount' => '6000'])->assertOk();
        $row = $this->codeRow($id);
        $this->assertNull($row->description);
        $this->assertSame('6000.00', $row->expected_amount_quotation);

        $log = PortalLog::query()->where('resource', 'manage.budget-codes')->where('record_id', (string) $id)->latest('id')->first();
        $this->assertSame('PATCH', $log?->action);
        $this->assertStringEndsWith('updated the budget code SEED-SETUP-011-20301223', (string) $log?->message);
    }

    public function test_a_budget_code_keeps_its_own_name_but_cannot_take_another(): void
    {
        $tracker = DB::connection('tracker');
        $first = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-012']);
        $second = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-013']);

        $this->editCode($second, ['name' => 'seed-setup-012'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A budget code named seed-setup-012 already exists.');
        $this->editCode($first, ['name' => 'Seed-Setup-012'])->assertOk();
        $this->editCode($first, ['name' => ' '])->assertStatus(422)->assertJsonPath('message', 'A budget code needs a name.');

        $this->assertSame('SEED-SETUP-013', $this->codeRow($second)->name);
        $this->assertSame('Seed-Setup-012', $this->codeRow($first)->name);
    }

    public function test_editing_or_funding_what_does_not_exist_is_refused(): void
    {
        $this->editCode(999999999, ['name' => 'SEED-SETUP-014'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That budget code does not exist.');
        $this->editEnvelope(999999999, ['name' => 'Seeded Setup Missing'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That budget envelope does not exist.');
        $this->fund(999999999, ['amount' => 10, 'receivedOn' => '2031-01-01'])
            ->assertNotFound()
            ->assertJsonPath('message', 'That budget envelope does not exist.');
    }

    // The reported case: envelopes under no code, and a code whose money went to an unlinked envelope.
    public function test_an_admin_moves_an_envelope_under_a_code_and_closes_it(): void
    {
        $tracker = DB::connection('tracker');
        $from = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-015']);
        $to = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-016']);
        $envelope = $this->seedEnvelope('Seeded Setup Mover');
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $envelope, 'budget_code_id' => $from]);

        $this->editEnvelope($envelope, ['budgetCodeId' => (string) $to])->assertOk();
        $this->assertSame([$to], $this->codesOf($envelope));
        $this->editEnvelope($envelope, ['budgetCodeId' => null])->assertOk();
        $this->assertSame([], $this->codesOf($envelope));
        $this->editEnvelope($envelope, ['budgetCodeId' => '999999999'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That budget code does not exist.');

        $this->editEnvelope($envelope, ['active' => false])->assertOk();
        $this->assertFalse((bool) $this->envelopeRow($envelope)->active);
        $this->assertStringEndsWith('closed the envelope Seeded Setup Mover', $this->lastEnvelopeLog($envelope));
        // Closed, it leaves the review's envelope picker.
        $open = collect($this->getJson('/api/development/portal/manage/budget-envelopes', $this->admin())->json('data'))->pluck('id');
        $this->assertNotContains((string) $envelope, $open);

        $this->editEnvelope($envelope, ['active' => true])->assertOk();
        $this->assertStringEndsWith('reopened the envelope Seeded Setup Mover', $this->lastEnvelopeLog($envelope));
    }

    public function test_an_envelope_edit_changes_only_what_was_sent(): void
    {
        $envelope = $this->seedEnvelope('Seeded Setup Edited', notes: 'Before');
        $this->seedEnvelope('Seeded Setup Other');

        // A plan cannot be taken away.
        $this->editEnvelope($envelope, ['planAmount' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter the plan amount.');
        $this->editEnvelope($envelope, ['name' => 'Seeded Setup Renamed', 'planAmount' => '2500', 'group' => 'Capital Expenditure'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Seeded Setup Renamed');
        $row = $this->envelopeRow($envelope);
        $this->assertSame('Capital Expenditure', $row->group_name);
        $this->assertSame('Monthly', $row->time_period);
        $this->assertSame('2500.00', $row->budget_amount);
        $this->assertSame('Before', $row->budget_notes);
        $this->assertStringEndsWith('updated the envelope Seeded Setup Renamed', $this->lastEnvelopeLog($envelope));

        $this->editEnvelope($envelope, ['name' => 'seeded setup other'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An envelope named seeded setup other already exists.');
        $this->editEnvelope($envelope, ['active' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Say whether the envelope is open with true or false.');
        $this->editEnvelope($envelope, ['timePeriod' => 'Weekly'])->assertStatus(422);
        $this->assertSame('Seeded Setup Renamed', $this->envelopeRow($envelope)->envelope_name);
    }

    // Two envelopes already share a name in the tracker; either can still be changed.
    public function test_an_envelope_sharing_its_name_can_still_be_changed(): void
    {
        $first = $this->seedEnvelope('Seeded Setup Twin');
        $this->seedEnvelope('Seeded Setup Twin');

        $this->editEnvelope($first, ['name' => 'seeded setup twin', 'notes' => 'The older one'])->assertOk();

        $this->assertSame('seeded setup twin', $this->envelopeRow($first)->envelope_name);
        $this->assertSame('The older one', $this->envelopeRow($first)->budget_notes);
    }

    // The reported asks (2026-10-08): funds are allocated from the account's unallocated money, so the
    // account's balance stays and nothing new is received.
    public function test_recording_funds_allocates_the_accounts_unallocated_money_to_the_envelope(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-017']);
        $envelope = $this->seedEnvelope('Seeded Setup Funded');
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $envelope, 'budget_code_id' => $code]);
        $account = $this->fundedAccount('Seeded Setup Holder', 'seeded-setup-holder@example.test', 3000);

        $response = $this->fund($envelope, [
            'amount' => '2500.50',
            'receivedOn' => '2031-02-01',
            'budgetCodeId' => (string) $code,
            'accountId' => (string) $account,
            'notes' => 'First transfer',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Seeded Setup Funded');

        $transaction = $tracker->table('transactions')->where('id', (int) $response->json('data.id'))->first();
        $this->assertSame('Credit - Allocation', $transaction->transaction_name);
        $this->assertSame('Credit', $transaction->type);
        $this->assertSame('2500.50', $transaction->amount);
        $this->assertSame('2031-02-01 00:00:00', (string) $transaction->transaction_date);
        // Only the note: who recorded it is created_by's to say, not a sentence tacked onto the note.
        $this->assertSame('First transfer', $transaction->description);
        $this->assertNotEmpty($transaction->created_by);
        $this->assertSame(1, $tracker->table('transactions_budget_codes')->where(['transaction_id' => $transaction->id, 'budget_code_id' => $code])->count());
        $this->assertSame(1, $tracker->table('transactions_accounts')->where(['transaction_id' => $transaction->id, 'account_id' => $account])->count());

        // Out of the account's unallocated money, in the same account.
        $out = $tracker->table('transactions')->where('id', '<', $transaction->id)->where('transaction_name', 'Expense - Allocation')->orderByDesc('id')->first();
        $this->assertNotNull($out);
        $this->assertSame(1, $tracker->table('transactions_accounts')->where(['transaction_id' => $out->id, 'account_id' => $account])->count());
        $this->assertSame(0, $tracker->table('transactions_envelopes')->where('transaction_id', $out->id)->count());

        // The envelope has it to spend, the code has received it, and the account still holds the same.
        $codes = $this->getJson('/api/development/portal/manage/budget-codes', $this->admin())->json();
        $this->assertEqualsWithDelta(2500.50, collect($codes['data'])->firstWhere('id', (string) $code)['received'], 0.001);
        // Linked to the code, it is listed under it rather than among the envelopes under none.
        $this->assertEqualsWithDelta(2500.50, collect(collect($codes['data'])->firstWhere('id', (string) $code)['envelopes'])->firstWhere('id', (string) $envelope)['remaining'], 0.001);
        $ledger = $this->getJson('/api/development/portal/manage/budget-transactions', $this->admin())->json();
        $team = collect($ledger['teams'])->firstWhere('accountId', (string) $account);
        $this->assertEqualsWithDelta(3000.00, $team['received'], 0.001);
        $this->assertEqualsWithDelta(0.00, $team['spent'], 0.001);
        $this->assertEqualsWithDelta(3000.00, $team['remaining'], 0.001);
        $this->assertEqualsWithDelta(499.50, $team['unallocated'], 0.001);
        // Listed once, as the money given to the envelope, under a name of its own; its mirror is not listed.
        $listed = collect($ledger['data'])->firstWhere('id', (string) $transaction->id);
        $this->assertTrue($listed['isAllocation']);
        $this->assertSame('Funds Allocated', $listed['name']);
        $this->assertSame([(string) $code], $listed['budgetCodeIds']);
        $this->assertNull(collect($ledger['data'])->firstWhere('id', (string) $out->id));
        $this->assertStringEndsWith("allocated ₱2,500.50 to Seeded Setup Funded from Seeded Setup Holder's unallocated funds", $this->lastEnvelopeLog($envelope));

        // More than is unallocated is asked about first.
        $this->fund($envelope, ['amount' => '500', 'receivedOn' => '2031-02-01', 'accountId' => (string) $account])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Setup Holder has only ₱499.50 unallocated. Confirm to go past it.');
        $this->fund($envelope, ['amount' => '500', 'receivedOn' => '2031-02-01', 'accountId' => (string) $account, 'allowOverspend' => true])->assertCreated();
    }

    public function test_funds_are_refused_without_an_amount_or_day_or_on_anything_closed(): void
    {
        $tracker = DB::connection('tracker');
        $envelope = $this->seedEnvelope('Seeded Setup Refused');
        $closed = $this->seedEnvelope('Seeded Setup Shut', active: false);
        $shutAccount = (int) $tracker->table('accounts')->insertGetId(['account_name' => 'Seeded Setup Shut Account', 'active' => false]);
        // The account the money would come from, seeded before the count.
        $this->fundedAccount('Seeded Setup Funder', 'seeded-setup-funder@example.test');
        $before = $tracker->table('transactions')->count();
        $day = '2031-02-01';

        $this->fund($envelope, ['receivedOn' => $day])->assertStatus(422)->assertJsonPath('message', 'Enter how much was received.');
        $this->fund($envelope, ['amount' => '0', 'receivedOn' => $day])->assertStatus(422)->assertJsonPath('message', 'The amount must be more than zero.');
        $this->fund($envelope, ['amount' => '10'])->assertStatus(422)->assertJsonPath('message', 'Choose the day the money was received.');
        $this->fund($closed, ['amount' => '10', 'receivedOn' => $day])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Setup Shut is closed. Reopen it before recording funds to it.');
        $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day, 'accountId' => (string) $shutAccount])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Setup Shut Account is closed, so it takes no new money.');
        $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day, 'accountId' => '999999999'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That account does not exist.');
        // An envelope nobody owns needs the account its money comes from.
        $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day, 'accountId' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose the account the money comes from.');
        $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day, 'budgetCodeId' => '999999999'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Setup Refused is under no budget code, so its funds are recorded against none.');

        $this->assertSame($before, $tracker->table('transactions')->count());
    }

    // The reported ask (2026-10-08): funds past the envelope's plan or its code's quotation are warned
    // about first, and recorded once the warning is confirmed.
    public function test_funds_past_the_plan_or_the_quotation_need_confirming(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-CAP', 'expected_amount_quotation' => 150]);
        $envelope = $this->seedEnvelope('Seeded Setup Capped');
        $tracker->table('envelopes_budget')->where('id', $envelope)->update(['budget_amount' => 100]);
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $envelope, 'budget_code_id' => $code]);
        $day = '2031-02-01';

        $this->fund($envelope, ['amount' => '60', 'receivedOn' => $day])->assertCreated();
        $this->fund($envelope, ['amount' => '50', 'receivedOn' => $day])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Recording ₱50.00 would credit Seeded Setup Capped ₱110.00, past its ₱100.00 plan.');

        // Raised, the plan lets it through; the quotation is next.
        $tracker->table('envelopes_budget')->where('id', $envelope)->update(['budget_amount' => 1000]);
        $this->fund($envelope, ['amount' => '50', 'receivedOn' => $day])->assertCreated();
        $this->fund($envelope, ['amount' => '50', 'receivedOn' => $day])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Recording ₱50.00 would bring SEED-SETUP-CAP to ₱160.00 received, past its ₱150.00 quotation.');

        // Confirmed, it is recorded past both.
        $this->fund($envelope, ['amount' => '500', 'receivedOn' => $day, 'allowOverPlan' => true])->assertCreated();
    }

    // The reported ask (2026-10-08): a new envelope can open with money in it, recorded with it.
    public function test_a_new_envelope_can_open_with_funds_recorded_into_it(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-OPEN', 'expected_amount_quotation' => 1000]);
        $owner = $this->ownerAccount();

        $this->addEnvelope(['name' => 'Seeded Setup No Day', 'fundsAmount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose the day the money was received.');
        $this->addEnvelope(['name' => 'Seeded Setup Too Much', 'budgetCodeId' => (string) $code, 'planAmount' => 200, 'fundsAmount' => 300, 'fundsReceivedOn' => '2031-02-01'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Recording ₱300.00 would credit Seeded Setup Too Much ₱300.00, past its ₱200.00 plan.');
        $this->assertNull($tracker->table('envelopes_budget')->where('envelope_name', 'Seeded Setup Too Much')->value('id'));

        $id = (int) $this->addEnvelope([
            'name' => 'Seeded Setup Opened', 'budgetCodeId' => (string) $code, 'planAmount' => 500, 'fundsAmount' => 400, 'fundsReceivedOn' => '2031-02-01',
        ])->assertCreated()
            ->assertJsonPath('data.funded', 400)
            ->assertJsonPath('data.remaining', 400)
            ->json('data.id');
        $credit = $tracker->table('transactions_envelopes as te')->join('transactions as t', 't.id', '=', 'te.transaction_id')
            ->where('te.envelope_id', $id)->first(['t.id', 't.type', 't.amount', 't.transaction_name']);
        $this->assertNotNull($credit);
        $this->assertSame(['Credit', '400.00', 'Credit - Allocation'], [$credit->type, $credit->amount, $credit->transaction_name]);
        $this->assertSame(1, $tracker->table('transactions_accounts')->where(['transaction_id' => $credit->id, 'account_id' => $owner])->count());
        $this->assertSame(1, $tracker->table('transactions_budget_codes')->where(['transaction_id' => $credit->id, 'budget_code_id' => $code])->count());

        // Past the plan, it is recorded once confirmed.
        $this->addEnvelope(['name' => 'Seeded Setup Confirmed', 'planAmount' => 10, 'fundsAmount' => 50, 'fundsReceivedOn' => '2031-02-01', 'allowOverPlan' => true])
            ->assertCreated()
            ->assertJsonPath('data.funded', 50);
    }

    // The reported ask (2026-10-08): a new envelope can take its opening money from another envelope.
    public function test_a_new_envelope_can_take_its_opening_funds_from_another_envelope(): void
    {
        $tracker = DB::connection('tracker');
        $source = $this->seedEnvelope('Seeded Setup Source');
        $credit = (int) $tracker->table('transactions')->insertGetId(['transaction_name' => 'Credit', 'transaction_date' => '2031-01-01 00:00:00', 'amount' => 300, 'type' => 'Credit']);
        $tracker->table('transactions_envelopes')->insert(['transaction_id' => $credit, 'envelope_id' => $source]);
        $remaining = fn (int $envelope): float => (float) $tracker->table('transactions_envelopes as te')->join('transactions as t', 't.id', '=', 'te.transaction_id')
            ->where('te.envelope_id', $envelope)->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'Credit' THEN t.amount ELSE -t.amount END), 0) AS left_over")->value('left_over');

        // More than the source has left is asked about first, and nothing is added.
        $this->addEnvelope(['name' => 'Seeded Setup Too Far', 'fundsAmount' => 500, 'fundsReceivedOn' => '2031-02-01', 'fundsSourceEnvelopeId' => (string) $source])
            ->assertStatus(422);
        $this->assertNull($tracker->table('envelopes_budget')->where('envelope_name', 'Seeded Setup Too Far')->value('id'));

        $id = (int) $this->addEnvelope([
            'name' => 'Seeded Setup Taken', 'fundsAmount' => 120, 'fundsReceivedOn' => '2031-02-01', 'fundsSourceEnvelopeId' => (string) $source,
        ])->assertCreated()->assertJsonPath('data.funded', 120)->json('data.id');

        $this->assertEqualsWithDelta(180.0, $remaining($source), 0.001);
        $this->assertEqualsWithDelta(120.0, $remaining($id), 0.001);
        $names = $tracker->table('transactions_envelopes as te')->join('transactions as t', 't.id', '=', 'te.transaction_id')
            ->whereIn('te.envelope_id', [$source, $id])->where('t.id', '!=', $credit)->pluck('t.transaction_name')->sort()->values()->all();
        $this->assertSame(['Credit - Envelope Transfer', 'Expense - Envelope Transfer'], $names);
    }

    // The reported ask (2026-10-08): the code is the one the envelope sits under, found for it.
    public function test_funds_are_recorded_against_the_code_the_envelope_sits_under(): void
    {
        $tracker = DB::connection('tracker');
        $own = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-OWN']);
        $other = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-OTHER']);
        $envelope = $this->seedEnvelope('Seeded Setup Coded');
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $envelope, 'budget_code_id' => $own]);
        $day = '2031-02-01';

        $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day, 'budgetCodeId' => (string) $other])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Setup Coded sits under SEED-SETUP-OWN, so its funds are recorded against that code.');

        $id = (int) $this->fund($envelope, ['amount' => '10', 'receivedOn' => $day])->assertCreated()->json('data.id');
        $this->assertSame(1, $tracker->table('transactions_budget_codes')->where(['transaction_id' => $id, 'budget_code_id' => $own])->count());
    }

    public function test_only_an_admin_edits_or_records_funds(): void
    {
        $member = ['Authorization' => 'Bearer '.$this->loginToken($this->activeMember())];
        $code = (int) DB::connection('tracker')->table('budget_codes')->insertGetId(['name' => 'SEED-SETUP-018']);
        $envelope = $this->seedEnvelope('Seeded Setup Guarded');

        $this->patchJson('/api/development/portal/manage/budget-codes/'.$code, ['name' => 'x'], $member)->assertForbidden();
        $this->patchJson('/api/development/portal/manage/budget-envelopes/'.$envelope, ['name' => 'x'], $member)->assertForbidden();
        $this->postJson('/api/development/portal/manage/budget-envelopes/'.$envelope.'/funds', ['amount' => 5, 'receivedOn' => '2031-01-01'], $member)->assertForbidden();

        $this->assertSame('SEED-SETUP-018', $this->codeRow($code)->name);
        $this->assertSame('Seeded Setup Guarded', $this->envelopeRow($envelope)->envelope_name);
    }

    private function seedEnvelope(string $name, bool $active = true, ?string $notes = null): int
    {
        return (int) DB::connection('tracker')->table('envelopes_budget')->insertGetId([
            'envelope_name' => $name,
            'group_name' => 'Maintenance',
            'time_period' => 'Monthly',
            'active' => $active,
            'budget_notes' => $notes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function editCode(int $id, array $body): TestResponse
    {
        return $this->patchJson('/api/development/portal/manage/budget-codes/'.$id, $body, $this->admin());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function editEnvelope(int $id, array $body): TestResponse
    {
        return $this->patchJson('/api/development/portal/manage/budget-envelopes/'.$id, $body, $this->admin());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fund(int $envelopeId, array $body): TestResponse
    {
        // Funds are allocated from an account's unallocated money; the tests about something else use one with plenty.
        $body += ['accountId' => $this->fundedAccount('Seeded Setup Funder', 'seeded-setup-funder@example.test')];

        return $this->postJson('/api/development/portal/manage/budget-envelopes/'.$envelopeId.'/funds', $body, $this->admin());
    }

    // An account holding unallocated money: a Credit tied to it and to no envelope.
    private function fundedAccount(string $name, string $email, float $unallocated = 1000000): int
    {
        $tracker = DB::connection('tracker');
        $id = $tracker->table('accounts')->where('account_name', $name)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $id = (int) $tracker->table('accounts')->insertGetId(['account_name' => $name, 'email' => $email, 'active' => true]);
        $credit = (int) $tracker->table('transactions')->insertGetId([
            'transaction_name' => 'Funds Received', 'transaction_date' => '2031-01-01 00:00:00', 'amount' => $unallocated, 'type' => 'Credit',
        ]);
        $tracker->table('transactions_accounts')->insert(['transaction_id' => $credit, 'account_id' => $id]);

        return $id;
    }

    private function codeRow(int $id): object
    {
        return DB::connection('tracker')->table('budget_codes')->where('id', $id)->first();
    }

    private function envelopeRow(int $id): object
    {
        return DB::connection('tracker')->table('envelopes_budget')->where('id', $id)->first();
    }

    /**
     * @return list<int>
     */
    private function codesOf(int $envelopeId): array
    {
        return DB::connection('tracker')->table('envelopes_budget_codes')->where('envelope_id', $envelopeId)
            ->pluck('budget_code_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    private function lastEnvelopeLog(int $envelopeId): string
    {
        return (string) PortalLog::query()->where('resource', 'manage.budget-envelopes')->where('record_id', (string) $envelopeId)
            ->latest('id')->value('message');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function addCode(array $body): TestResponse
    {
        // A new code needs a quotation; the tests that are about something else are given one.
        $body += ['expectedAmount' => 100000];

        return $this->postJson('/api/development/portal/manage/budget-codes', $body, $this->admin());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function addEnvelope(array $body): TestResponse
    {
        // A new envelope needs an owner and a plan; the tests that are about something else are given them.
        $body += ['ownerAccountId' => $this->ownerAccount(), 'planAmount' => 100000];

        return $this->postJson('/api/development/portal/manage/budget-envelopes', $body, $this->admin());
    }

    private function ownerAccount(): int
    {
        return $this->fundedAccount('Seeded Setup Owner', 'seeded-setup-owner@example.test');
    }

    private function codeCount(): int
    {
        return DB::connection('tracker')->table('budget_codes')->count();
    }

    private function envelopeCount(): int
    {
        return DB::connection('tracker')->table('envelopes_budget')->count();
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        return ['Authorization' => 'Bearer '.$this->loginToken($this->approver())];
    }

    private function approver(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereNotNull('first_name')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }
}
