<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Moving money in the transaction tracker from Finance: funds added to an account, transfers between
 * accounts and between envelopes, transactions added by hand, and the transfers an expense on another
 * account's envelope leaves pending.
 *
 * Runs against the real tracker database inside a transaction, so the live accounts and envelopes sit
 * alongside the seeded ones. Assertions read the seeded records' own balances, never a total.
 */
class PortalBudgetMovementsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'tracker', 'core'];

    private const BASE = '/api/development/portal/manage/';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        config(['products.catalog.transaction-tracker.enabled' => true]);
    }

    public function test_the_accounts_list_each_balance_and_marks_the_readers_own(): void
    {
        $own = $this->account('Seeded Own Account', (string) $this->approver()->email, 900.00);
        $other = $this->account('Seeded Other Account', 'other.seeded@example.test', 250.00);
        $this->spendFromAccount($other, 100.00);

        $listed = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', true)
            ->json('data'))->keyBy('id');

        $this->assertEqualsWithDelta(900.00, $listed[(string) $own]['remaining'], 0.001);
        $this->assertTrue($listed[(string) $own]['isOwn']);
        $this->assertEqualsWithDelta(250.00, $listed[(string) $other]['received'], 0.001);
        $this->assertEqualsWithDelta(150.00, $listed[(string) $other]['remaining'], 0.001);
        $this->assertFalse($listed[(string) $other]['isOwn']);
    }

    // The reported ask (2026-10-08): a new envelope's money given from its owner's unallocated funds.
    public function test_a_new_envelope_can_be_given_its_owners_unallocated_funds_keeping_the_balance(): void
    {
        $owner = $this->account('Seeded Allocating Account', 'allocating.seeded@example.test', 900.00);
        $add = fn (array $body): TestResponse => $this->postJson(self::BASE.'budget-envelopes', $body + [
            'group' => 'Maintenance', 'timePeriod' => 'Monthly', 'planAmount' => 5000, 'ownerAccountId' => $owner,
            'fundsReceivedOn' => '2026-10-08',
        ], $this->admin());
        $listed = fn (): array => collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->assertOk()->json('data'))
            ->keyBy('id')->get((string) $owner);

        $first = $listed();
        $this->assertEqualsWithDelta(900.00, $first['unallocated'], 0.001);
        $this->assertEqualsWithDelta(0.00, $first['allocated'], 0.001);

        $id = (int) $add(['name' => 'Seeded Allocated Envelope', 'fundsAmount' => 300])->assertCreated()->assertJsonPath('data.funded', 300)->json('data.id');
        $after = $listed();
        $this->assertEqualsWithDelta(900.00, $after['remaining'], 0.001);
        $this->assertEqualsWithDelta(600.00, $after['unallocated'], 0.001);
        $this->assertEqualsWithDelta(300.00, $after['allocated'], 0.001);
        $this->assertEqualsWithDelta(300.00, $this->envelopeRemaining($id), 0.001);

        // More than is unallocated is asked about first, and nothing is added.
        $add(['name' => 'Seeded Allocated Too Far', 'fundsAmount' => 700])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Allocating Account has only ₱600.00 unallocated. Confirm to go past it.');
        $this->assertNull(DB::connection('tracker')->table('envelopes_budget')->where('envelope_name', 'Seeded Allocated Too Far')->value('id'));
        $add(['name' => 'Seeded Allocated Too Far', 'fundsAmount' => 700, 'allowOverspend' => true])->assertCreated();
        $this->assertEqualsWithDelta(-100.00, $listed()['unallocated'], 0.001);
        $this->assertEqualsWithDelta(900.00, $listed()['remaining'], 0.001);
        // Moved inside the account, it is neither received nor spent.
        $this->assertEqualsWithDelta(900.00, $listed()['received'], 0.001);
        $this->assertEqualsWithDelta(0.00, $listed()['spent'], 0.001);
    }

    public function test_adding_funds_credits_the_account_and_logs_it(): void
    {
        $account = $this->account('Seeded Funded Account', 'funded.seeded@example.test', 0.00);

        $this->postJson(self::BASE.'budget-accounts/'.$account.'/funds', [
            'amount' => 1500,
            'receivedOn' => '2026-10-07',
            'notes' => 'Quarterly allotment',
        ], $this->admin())->assertCreated()->assertJsonPath('data.name', 'Seeded Funded Account');

        $this->assertEqualsWithDelta(1500.00, $this->accountRemaining($account), 0.001);
        $this->assertSame('Funds Received', $this->lastRowOf($account)->transaction_name);
        $this->assertTrue($this->logged('manage.budget-accounts', 'added ₱1,500.00 to the account Seeded Funded Account'));
    }

    public function test_a_closed_account_takes_no_funds(): void
    {
        $closed = $this->account('Seeded Closed Account', 'closed.seeded@example.test', 0.00, active: false);

        $this->postJson(self::BASE.'budget-accounts/'.$closed.'/funds', ['amount' => 10, 'receivedOn' => '2026-10-07'], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Closed Account is closed, so it takes no new money.');
    }

    // A transfer is the tracker's own pair: an Expense out of one account, a Credit into the other.
    public function test_a_transfer_moves_money_between_accounts_as_a_pair(): void
    {
        $source = $this->account('Seeded Source Account', 'source.seeded@example.test', 1000.00);
        $destination = $this->account('Seeded Destination Account', 'destination.seeded@example.test', 0.00);

        $this->postJson(self::BASE.'budget-accounts/transfers', [
            'sourceAccountId' => $source,
            'destinationAccountId' => $destination,
            'amount' => 300,
            'transferredOn' => '2026-10-07',
            'reason' => 'Site petty cash',
        ], $this->admin())->assertCreated();

        $this->assertEqualsWithDelta(700.00, $this->accountRemaining($source), 0.001);
        $this->assertEqualsWithDelta(300.00, $this->accountRemaining($destination), 0.001);
        $this->assertSame('Expense - Account Transfer', $this->lastRowOf($source)->transaction_name);
        $this->assertSame('Credit - Account Transfer', $this->lastRowOf($destination)->transaction_name);
        $this->assertSame(2, DB::connection('tracker')->table('transactions_transfer_source_accounts')->where('account_id', $source)->count());
        $this->assertSame(2, DB::connection('tracker')->table('transactions_transfer_destination_accounts')->where('account_id', $destination)->count());
    }

    // The reported gap: a transfer made straight away is listed with the owed ones, as completed.
    public function test_a_transfer_made_straight_away_is_listed_as_completed(): void
    {
        $source = $this->account('Seeded Listed Source', 'listed.source@example.test', 500.00);
        $destination = $this->account('Seeded Listed Destination', 'listed.destination@example.test', 0.00);
        $this->postJson(self::BASE.'budget-accounts/transfers', [
            'sourceAccountId' => $source, 'destinationAccountId' => $destination, 'amount' => 199, 'transferredOn' => '2026-10-07', 'reason' => 'Site petty cash',
        ], $this->admin())->assertCreated();

        $listed = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->assertOk()->json('transfers'))
            ->where('sourceAccountId', (string) $source)
            ->values();

        $this->assertCount(1, $listed);
        $this->assertSame('Completed', $listed[0]['status']);
        $this->assertSame('Seeded Listed Destination', $listed[0]['destinationName']);
        $this->assertSame('Site petty cash', $listed[0]['reason']);
        $this->assertSame('2026-10-07', $listed[0]['settledOn']);
        $this->assertEqualsWithDelta(199.00, $listed[0]['amount'], 0.001);
        $this->assertStringStartsWith('ledger-', $listed[0]['id']);
    }

    public function test_a_transfer_past_the_balance_waits_for_confirmation(): void
    {
        $source = $this->account('Seeded Thin Account', 'thin.seeded@example.test', 100.00);
        $destination = $this->account('Seeded Receiving Account', 'receiving.seeded@example.test', 0.00);
        $body = ['sourceAccountId' => $source, 'destinationAccountId' => $destination, 'amount' => 250, 'transferredOn' => '2026-10-07'];

        $this->postJson(self::BASE.'budget-accounts/transfers', $body, $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Thin Account has only ₱100.00 left. Confirm to go past it.');
        $this->assertEqualsWithDelta(100.00, $this->accountRemaining($source), 0.001);

        $this->postJson(self::BASE.'budget-accounts/transfers', $body + ['allowOverdraw' => true], $this->admin())->assertCreated();
        $this->assertEqualsWithDelta(-150.00, $this->accountRemaining($source), 0.001);
    }

    public function test_a_transfer_needs_two_different_accounts(): void
    {
        $account = $this->account('Seeded Lone Account', 'lone.seeded@example.test', 100.00);

        $this->postJson(self::BASE.'budget-accounts/transfers', [
            'sourceAccountId' => $account, 'destinationAccountId' => $account, 'amount' => 10, 'transferredOn' => '2026-10-07',
        ], $this->admin())->assertStatus(422)->assertJsonPath('message', 'Choose two different accounts.');
    }

    public function test_an_envelope_takes_an_owner_which_the_envelopes_list_names(): void
    {
        $owner = $this->account('Seeded Owner Account', 'owner.seeded@example.test', 0.00);

        $id = (int) $this->postJson(self::BASE.'budget-envelopes', [
            'name' => 'Seeded Owned Envelope', 'group' => 'Maintenance', 'timePeriod' => 'Monthly', 'planAmount' => 1000, 'ownerAccountId' => $owner,
        ], $this->admin())->assertCreated()->assertJsonPath('data.ownerAccountId', (string) $owner)->json('data.id');

        $this->assertSame('Seeded Owner Account', $this->listedEnvelope($id)['ownerName']);

        $this->patchJson(self::BASE.'budget-envelopes/'.$id, ['ownerAccountId' => null], $this->admin())->assertOk();
        $this->assertNull($this->listedEnvelope($id)['ownerAccountId']);
    }

    public function test_an_envelope_owner_has_to_be_an_open_account(): void
    {
        $closed = $this->account('Seeded Closed Owner', 'closed.owner@example.test', 0.00, active: false);

        $this->postJson(self::BASE.'budget-envelopes', [
            'name' => 'Seeded Unowned Envelope', 'group' => 'Maintenance', 'timePeriod' => 'Monthly', 'planAmount' => 1000, 'ownerAccountId' => $closed,
        ], $this->admin())->assertStatus(422)->assertJsonPath('message', 'Seeded Closed Owner is closed, so it takes no new money.');
    }

    // The reported gap: funds recorded into an owned envelope land in its owner's account, never another's.
    public function test_funds_recorded_into_an_owned_envelope_are_held_in_its_owners_account(): void
    {
        $nat = $this->account('Seeded Funds Owner', 'funds.owner@example.test', 2000.00);
        $steven = $this->account('Seeded Funds Other', 'funds.other@example.test', 0.00);
        $envelope = $this->envelope('Seeded Owned Testing', 0.00, $nat);
        $fund = fn (array $body): TestResponse => $this->postJson(self::BASE.'budget-envelopes/'.$envelope.'/funds', $body + [
            'amount' => 1000, 'receivedOn' => '2026-10-07',
        ], $this->admin());

        $fund(['accountId' => $steven])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Owned Testing belongs to Seeded Funds Owner, so its funds are held in that account.');
        $this->assertEqualsWithDelta(0.00, $this->accountRemaining($steven), 0.001);

        // Sent with no account, or with the owner's, it is allocated from the owner's, whose balance stays.
        $fund([])->assertCreated();
        $fund(['accountId' => $nat])->assertCreated();
        $this->assertEqualsWithDelta(2000.00, $this->accountRemaining($nat), 0.001);
        $this->assertEqualsWithDelta(2000.00, $this->envelopeRemaining($envelope), 0.001);
        $this->assertTrue(PortalLog::query()->where('resource', 'manage.budget-envelopes')->where('payload->accountId', $nat)->exists());
    }

    // One owner's envelopes: only the envelopes change, and the account's money stays where it was.
    public function test_an_envelope_transfer_within_one_owner_moves_only_the_envelopes(): void
    {
        $owner = $this->account('Seeded Single Owner', 'single.owner@example.test', 0.00);
        $from = $this->envelope('Seeded PC Envelope', 800.00, $owner);
        $to = $this->envelope('Seeded Switches Envelope', 0.00, $owner);

        $this->transferEnvelopes($from, $to, 300)->assertCreated()->assertJsonPath('data.crossesAccounts', false);

        $this->assertEqualsWithDelta(500.00, $this->envelopeRemaining($from), 0.001);
        $this->assertEqualsWithDelta(300.00, $this->envelopeRemaining($to), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->accountRemaining($owner), 0.001);
    }

    // The reported ask: envelopes of two owners move the money across their accounts, then into the envelope.
    public function test_an_envelope_transfer_between_two_owners_crosses_their_accounts(): void
    {
        $nat = $this->account('Seeded Nat', 'nat.seeded@example.test', 1000.00);
        $ben = $this->account('Seeded Ben', 'ben.seeded@example.test', 0.00);
        $natPc = $this->envelope('Seeded Nat PC', 800.00, $nat);
        $benFood = $this->envelope('Seeded Ben Food', 0.00, $ben);

        $this->transferEnvelopes($natPc, $benFood, 200)->assertCreated()->assertJsonPath('data.crossesAccounts', true);

        $this->assertEqualsWithDelta(600.00, $this->envelopeRemaining($natPc), 0.001);
        $this->assertEqualsWithDelta(200.00, $this->envelopeRemaining($benFood), 0.001);
        $this->assertEqualsWithDelta(800.00, $this->accountRemaining($nat), 0.001);
        $this->assertEqualsWithDelta(200.00, $this->accountRemaining($ben), 0.001);
        $this->assertSame('Expense - Envelope Transfer', $this->lastRowOf($nat)->transaction_name);
        $this->assertSame('Credit - Envelope Transfer', $this->lastRowOf($ben)->transaction_name);
    }

    // The reported gap: an envelope nobody owns names the account its side uses, or takes the other side's.
    public function test_an_envelope_with_no_owner_uses_the_account_chosen_for_it_or_the_other_sides(): void
    {
        $nat = $this->account('Seeded Side Nat', 'side.nat@example.test', 1000.00);
        $steven = $this->account('Seeded Side Steven', 'side.steven@example.test', 1000.00);
        $loose = $this->envelope('Seeded Loose Pool', 900.00);
        $stevens = $this->envelope('Seeded Steven Supplies', 0.00, $steven);
        $other = $this->envelope('Seeded Loose Other', 0.00);

        // Into Steven's envelope with no account chosen: the money stays in Steven's account.
        $this->transferEnvelopes($loose, $stevens, 100)->assertCreated()->assertJsonPath('data.crossesAccounts', false);
        $this->assertEqualsWithDelta(1000.00, $this->accountRemaining($steven), 0.001);

        // Taken from Nat's account into Steven's envelope: it crosses Nat's account to Steven's.
        $this->transferEnvelopes($loose, $stevens, 200, ['sourceAccountId' => $nat])->assertCreated()->assertJsonPath('data.crossesAccounts', true);
        $this->assertEqualsWithDelta(800.00, $this->accountRemaining($nat), 0.001);
        $this->assertEqualsWithDelta(1200.00, $this->accountRemaining($steven), 0.001);

        // Between two envelopes nobody owns, no account moves unless two are chosen.
        $this->transferEnvelopes($loose, $other, 50)->assertCreated()->assertJsonPath('data.crossesAccounts', false);
        $this->transferEnvelopes($loose, $other, 50, ['sourceAccountId' => $steven, 'destinationAccountId' => $nat])->assertCreated();
        $this->assertEqualsWithDelta(850.00, $this->accountRemaining($nat), 0.001);
        $this->assertEqualsWithDelta(1150.00, $this->accountRemaining($steven), 0.001);
        $this->assertEqualsWithDelta(500.00, $this->envelopeRemaining($loose), 0.001);

        // An owned side cannot be pointed at another account.
        $this->transferEnvelopes($loose, $stevens, 10, ['destinationAccountId' => $nat])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Steven Supplies belongs to Seeded Side Steven, so its side of the transfer uses that account.');
    }

    public function test_an_envelope_transfer_is_refused_into_a_closed_envelope_or_past_what_is_left(): void
    {
        $from = $this->envelope('Seeded Small Envelope', 50.00);
        $closed = $this->envelope('Seeded Shut Envelope', 0.00, active: false);
        $open = $this->envelope('Seeded Open Envelope', 0.00);

        $this->transferEnvelopes($from, $closed, 10)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Shut Envelope is closed. Reopen it before moving money into it.');
        $this->transferEnvelopes($from, $open, 80)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Small Envelope has only ₱50.00 left. Confirm to go past it.');
        $this->transferEnvelopes($from, $open, 80, ['allowOverspend' => true])->assertCreated();
        $this->assertEqualsWithDelta(-30.00, $this->envelopeRemaining($from), 0.001);
    }

    public function test_income_credits_the_envelope_and_the_account(): void
    {
        $account = $this->account('Seeded Income Account', 'income.seeded@example.test', 0.00);
        $envelope = $this->envelope('Seeded Income Envelope', 0.00);

        $this->addTransaction(['type' => 'Income', 'name' => 'Client deposit', 'amount' => 400, 'envelopeId' => $envelope, 'accountId' => $account, 'payorPayee' => 'Seeded Client'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'Credit')
            ->assertJsonPath('data.pendingTransfer', null);

        $this->assertEqualsWithDelta(400.00, $this->envelopeRemaining($envelope), 0.001);
        $this->assertEqualsWithDelta(400.00, $this->accountRemaining($account), 0.001);
        $this->assertSame('Seeded Client', $this->lastRowOf($account)->payor_payee);
    }

    // The reported ask: a description reads as it was written, without the portal's own sentence on it.
    public function test_a_description_is_the_note_alone_and_an_older_one_reads_without_the_portal_line(): void
    {
        $account = $this->account('Seeded Note Account', 'note.seeded@example.test', 0.00);
        $envelope = $this->envelope('Seeded Note Envelope', 0.00);

        $this->addTransaction(['type' => 'Income', 'name' => 'Client deposit', 'amount' => 50, 'envelopeId' => $envelope, 'accountId' => $account, 'notes' => 'dasdsad'])
            ->assertCreated();
        $row = $this->lastRowOf($account);
        $this->assertSame('dasdsad', $row->description);
        $this->assertNotEmpty($row->created_by);

        $this->addTransaction(['type' => 'Income', 'name' => 'No note', 'amount' => 5, 'envelopeId' => $envelope, 'accountId' => $account])
            ->assertCreated();
        $this->assertNull($this->lastRowOf($account)->description);

        // A row written before the change still carries the sentence; the list leaves it off.
        DB::connection('tracker')->table('transactions')->where('id', $row->id)
            ->update(['description' => 'dasdsad Recorded in the Ikaika portal by Jhon Noriel Malabanan.']);
        Cache::flush();
        $listed = collect($this->getJson(self::BASE.'budget-transactions', $this->admin())->assertOk()->json('data'))
            ->firstWhere('id', (string) $row->id);
        $this->assertSame('dasdsad', $listed['description']);
    }

    public function test_an_expense_on_the_payers_own_envelope_leaves_nothing_pending(): void
    {
        $nat = $this->account('Seeded Own Payer', 'own.payer@example.test', 500.00);
        $envelope = $this->envelope('Seeded Own Supplies', 500.00, $nat);

        $this->addTransaction(['type' => 'Expense', 'name' => 'Cables', 'amount' => 120, 'envelopeId' => $envelope, 'accountId' => $nat])
            ->assertCreated()
            ->assertJsonPath('data.pendingTransfer', null);

        $this->assertSame(0, $this->transfersFrom($nat)->count());
    }

    /*
     * The reported scenario: Nat spends on transport out of Ben's Transportation envelope. It goes
     * through and Ben's envelope is spent, Nat's account paid, so Ben's account owes Nat's the amount
     * until someone completes the transfer.
     */
    public function test_an_expense_on_another_accounts_envelope_goes_through_and_leaves_a_transfer_pending(): void
    {
        $nat = $this->account('Seeded Nat Payer', 'nat.payer@example.test', 0.00);
        $ben = $this->account('Seeded Ben Owner', 'ben.owner@example.test', 2000.00);
        $transport = $this->envelope('Seeded Ben Transportation', 1000.00, $ben);

        $this->addTransaction(['type' => 'Expense', 'name' => 'Grab to site', 'amount' => 500, 'envelopeId' => $transport, 'accountId' => $nat])
            ->assertCreated()
            ->assertJsonPath('data.pendingTransfer.sourceName', 'Seeded Ben Owner')
            ->assertJsonPath('data.pendingTransfer.destinationName', 'Seeded Nat Payer');

        $this->assertEqualsWithDelta(500.00, $this->envelopeRemaining($transport), 0.001);
        $this->assertEqualsWithDelta(-500.00, $this->accountRemaining($nat), 0.001);
        $pending = $this->transfersFrom($ben)->sole();
        $this->assertSame('Pending', $pending->status);
        $this->assertSame((string) $nat, (string) $pending->destination_account_id);
        $this->assertSame('500.00', $pending->amount);
        $this->assertSame('Cross-account envelope expense on Seeded Ben Transportation: Grab to site', $pending->reason);

        $listed = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->json('transfers'))->keyBy('id');
        $this->assertSame('Pending', $listed[(string) $pending->id]['status']);
        $this->assertSame('Seeded Ben Owner', $listed[(string) $pending->id]['sourceName']);

        // Completing it pays Nat back out of Ben's account.
        $this->postJson(self::BASE.'budget-accounts/transfers/'.$pending->id.'/complete', ['transferredOn' => '2026-10-08'], $this->admin())->assertOk();
        $this->assertEqualsWithDelta(0.00, $this->accountRemaining($nat), 0.001);
        $this->assertEqualsWithDelta(1500.00, $this->accountRemaining($ben), 0.001);
        $this->assertSame('Completed', $this->transfersFrom($ben)->sole()->status);
        // Settled, it is listed once: as the owed transfer, not again from the rows it wrote.
        $fromBen = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->json('transfers'))->where('sourceAccountId', (string) $ben);
        $this->assertSame([(string) $pending->id], $fromBen->pluck('id')->values()->all());

        // A move made with no reason reads as what it was.
        $this->postJson(self::BASE.'budget-accounts/transfers', [
            'sourceAccountId' => $ben, 'destinationAccountId' => $nat, 'amount' => 10, 'transferredOn' => '2026-10-09',
        ], $this->admin())->assertCreated();
        $plain = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->json('transfers'))
            ->where('sourceAccountId', (string) $ben)->firstWhere('settledOn', '2026-10-09');
        $this->assertSame('Account Transfer', $plain['reason']);

        $this->postJson(self::BASE.'budget-accounts/transfers/'.$pending->id.'/complete', [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'That transfer is already completed.');
    }

    public function test_cancelling_a_pending_transfer_moves_nothing(): void
    {
        $nat = $this->account('Seeded Cancel Payer', 'cancel.payer@example.test', 0.00);
        $ben = $this->account('Seeded Cancel Owner', 'cancel.owner@example.test', 300.00);
        $envelope = $this->envelope('Seeded Cancel Envelope', 300.00, $ben);
        $this->addTransaction(['type' => 'Expense', 'name' => 'Toll', 'amount' => 80, 'envelopeId' => $envelope, 'accountId' => $nat])->assertCreated();
        $pending = $this->transfersFrom($ben)->sole();

        $this->postJson(self::BASE.'budget-accounts/transfers/'.$pending->id.'/cancel', [], $this->admin())->assertOk();

        $this->assertSame('Cancelled', $this->transfersFrom($ben)->sole()->status);
        $this->assertEqualsWithDelta(300.00, $this->accountRemaining($ben), 0.001);
    }

    public function test_a_transaction_needs_a_type_and_an_envelope(): void
    {
        $this->addTransaction(['type' => 'Transfer', 'name' => 'Odd', 'amount' => 10, 'occurredOn' => '2026-10-07'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose Income or Expense for the type.');
        $this->addTransaction(['type' => 'Expense', 'name' => 'Loose', 'amount' => 10])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose the envelope this transaction comes out of or goes into.');

        // The reported ask (2026-10-09): an account alone is not enough; it is funded from Accounts instead.
        $account = $this->account('Seeded Loose Account', 'loose.seeded@example.test', 500.00);
        $before = DB::connection('tracker')->table('transactions')->count();
        $this->addTransaction(['type' => 'Income', 'name' => 'Loose deposit', 'amount' => 100, 'accountId' => $account])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose the envelope this transaction comes out of or goes into.');
        $this->assertSame($before, DB::connection('tracker')->table('transactions')->count());
    }

    // The reported ask (2026-10-08): no money is recorded against a budget code without its envelope.
    public function test_a_budget_code_comes_only_from_the_envelope(): void
    {
        $tracker = DB::connection('tracker');
        $account = $this->account('Seeded Code Account', 'code.seeded@example.test', 500.00);
        $own = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-MOVE-OWN']);
        $other = (int) $tracker->table('budget_codes')->insertGetId(['name' => 'SEED-MOVE-OTHER']);
        $envelope = $this->envelope('Seeded Coded Envelope', 0.00);
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $envelope, 'budget_code_id' => $own]);

        // Sent with another code, it is recorded against the envelope's own.
        $id = (int) $this->addTransaction([
            'type' => 'Income', 'name' => 'Coded deposit', 'amount' => 100, 'envelopeId' => $envelope, 'accountId' => $account, 'budgetCodeId' => $other,
        ])->assertCreated()->json('data.id');
        $this->assertSame([$own], $tracker->table('transactions_budget_codes')->where('transaction_id', $id)->pluck('budget_code_id')->map(fn ($code) => (int) $code)->all());

        // An envelope under no code records none.
        $loose = $this->envelope('Seeded Uncoded Envelope', 0.00);
        $plain = (int) $this->addTransaction(['type' => 'Income', 'name' => 'Plain deposit', 'amount' => 10, 'envelopeId' => $loose, 'accountId' => $account])
            ->assertCreated()->json('data.id');
        $this->assertSame(0, $tracker->table('transactions_budget_codes')->where('transaction_id', $plain)->count());
    }

    public function test_a_member_reaches_none_of_the_money_routes(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->loginToken($this->activeMember())];

        $this->getJson(self::BASE.'budget-accounts', $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-accounts/1/funds', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-accounts/transfers', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-accounts/transfers/1/complete', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-envelopes/transfers', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-transactions', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-transactions/receipts', [], $headers)->assertForbidden();
        $this->postJson(self::BASE.'budget-accounts', [], $headers)->assertForbidden();
    }

    // The reported ask: Finance / Accounts opens a new account, held by a person and open at once.
    public function test_an_account_is_added_open_and_held_by_one_person(): void
    {
        $holders = collect($this->getJson(self::BASE.'budget-accounts', $this->admin())->assertOk()->json('holders'));
        $holder = $holders->first();
        $this->assertNotNull($holder, 'Somebody without an open account was needed.');
        $person = Employee::query()->findOrFail((int) $holder['employeeId']);

        $id = (int) $this->postJson(self::BASE.'budget-accounts', [
            'name' => 'Seeded New Account',
            'holderId' => $holder['employeeId'],
            'accountType' => 'Savings',
            'institution' => 'BDO',
        ], $this->admin())->assertCreated()->assertJsonPath('data.name', 'Seeded New Account')->json('data.id');

        $row = DB::connection('tracker')->table('accounts')->where('id', $id)->first();
        $this->assertSame(strtolower(trim((string) $person->email)), $row->email);
        $this->assertSame(1, (int) $row->active);
        $this->assertSame('Savings', $row->account_type);
        $this->assertSame('BDO', $row->institution);
        $this->assertTrue($this->logged('manage.budget-accounts', 'added the account Seeded New Account'));

        $page = $this->getJson(self::BASE.'budget-accounts', $this->admin())->assertOk();
        $listed = collect($page->json('data'))->firstWhere('id', (string) $id);
        $this->assertSame($person->department, $listed['team']);
        $this->assertNotContains($holder['employeeId'], array_column($page->json('holders'), 'employeeId'));

        // One name is one account's, and a person holds one open account.
        $this->postJson(self::BASE.'budget-accounts', ['name' => 'seeded new account'], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'An account named seeded new account already exists.');
        $this->postJson(self::BASE.'budget-accounts', ['name' => 'Seeded Second Account', 'holderId' => $holder['employeeId']], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_ends_with($message, 'already holds the open account Seeded New Account.'));
        $this->postJson(self::BASE.'budget-accounts', ['holderId' => $holder['employeeId']], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'An account needs a name.');
    }

    // The reported ask: a transaction takes an optional receipt photo, shown with it in the ledger.
    public function test_a_receipt_photo_uploads_then_lands_on_the_added_transaction(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/auto/upload' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/transactions/fare.jpg',
                'original_filename' => 'fare',
                'bytes' => 1200,
            ], 200),
        ]);
        $account = $this->account('Seeded Receipt Account', 'receipt.seeded@example.test', 0.00);
        $envelope = $this->envelope('Seeded Receipt Envelope', 0.00);

        $uploaded = $this->post(self::BASE.'budget-transactions/receipts', ['file' => UploadedFile::fake()->create('fare.jpg', 12, 'image/jpeg')], $this->admin())
            ->assertCreated();
        $receiptId = $uploaded->json('data.receiptId');
        $this->assertIsString($receiptId);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->body(), 'ikaika-portal/transactions'));

        $this->addTransaction(['type' => 'Income', 'name' => 'Fare refund', 'amount' => 20, 'envelopeId' => $envelope, 'accountId' => $account, 'receiptId' => $receiptId])
            ->assertCreated();
        $row = $this->lastRowOf($account);
        $stored = DB::connection('tracker')->table('attachments')->where(['table_name' => 'transactions', 'record_id' => $row->id, 'field_name' => 'receipt'])->first();
        $this->assertNotNull($stored);
        $this->assertSame('image/jpeg', $stored->mime_type);

        Cache::flush();
        $listed = collect($this->getJson(self::BASE.'budget-transactions', $this->admin())->assertOk()->json('data'))->firstWhere('id', (string) $row->id);
        $this->assertSame('https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/transactions/fare.jpg', $listed['receipts'][0]['fileUrl']);
        $this->assertSame('https://res.cloudinary.com/test-cloud/image/upload/c_fill,h_96,w_96,f_auto,q_auto/v1/ikaika-portal/transactions/fare.jpg', $listed['receipts'][0]['thumbUrl']);

        // An upload is named once; a second use, or a made-up id, finds nothing.
        $this->addTransaction(['type' => 'Income', 'name' => 'Again', 'amount' => 5, 'envelopeId' => $envelope, 'accountId' => $account, 'receiptId' => $receiptId])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That receipt upload has expired. Choose the photo again.');
        $this->postJson(self::BASE.'budget-transactions/receipts', [], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose an image or PDF receipt.');
    }

    public function test_with_the_tracker_switched_off_nothing_moves(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);

        $this->getJson(self::BASE.'budget-accounts', $this->admin())->assertOk()->assertJsonPath('available', false);
        $this->postJson(self::BASE.'budget-accounts/transfers', ['sourceAccountId' => 1, 'destinationAccountId' => 2, 'amount' => 1, 'transferredOn' => '2026-10-07'], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('message', 'The transaction tracker is switched off here, so nothing can be added to it.');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transferEnvelopes(int $from, int $to, float $amount, array $extra = []): TestResponse
    {
        return $this->postJson(self::BASE.'budget-envelopes/transfers', [
            'sourceEnvelopeId' => $from,
            'destinationEnvelopeId' => $to,
            'amount' => $amount,
            'transferredOn' => '2026-10-07',
        ] + $extra, $this->admin());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function addTransaction(array $body): TestResponse
    {
        return $this->postJson(self::BASE.'budget-transactions', $body + ['occurredOn' => '2026-10-07'], $this->admin());
    }

    // An account seeded with a Credit for what it opens holding.
    private function account(string $name, string $email, float $holding, bool $active = true): int
    {
        $tracker = DB::connection('tracker');
        $id = (int) $tracker->table('accounts')->insertGetId(['account_name' => $name, 'email' => $email, 'active' => $active]);
        if ($holding > 0) {
            $credit = (int) $tracker->table('transactions')->insertGetId([
                'transaction_name' => 'Credit', 'transaction_date' => '2026-09-01 00:00:00', 'amount' => $holding, 'type' => 'Credit',
            ]);
            $tracker->table('transactions_accounts')->insert(['transaction_id' => $credit, 'account_id' => $id]);
        }

        return $id;
    }

    private function spendFromAccount(int $accountId, float $amount): void
    {
        $tracker = DB::connection('tracker');
        $expense = (int) $tracker->table('transactions')->insertGetId([
            'transaction_name' => 'Expense', 'transaction_date' => '2026-09-02 00:00:00', 'amount' => $amount, 'type' => 'Expense',
        ]);
        $tracker->table('transactions_accounts')->insert(['transaction_id' => $expense, 'account_id' => $accountId]);
    }

    // An envelope seeded with a Credit for what it opens holding, owned by an account when given one.
    private function envelope(string $name, float $holding, ?int $ownerId = null, bool $active = true): int
    {
        $tracker = DB::connection('tracker');
        $id = (int) $tracker->table('envelopes_budget')->insertGetId([
            'envelope_name' => $name, 'budget_amount' => $holding, 'group_name' => 'Maintenance', 'time_period' => 'Monthly', 'active' => $active,
        ]);
        if ($holding > 0) {
            $credit = (int) $tracker->table('transactions')->insertGetId([
                'transaction_name' => 'Credit', 'transaction_date' => '2026-09-01 00:00:00', 'amount' => $holding, 'type' => 'Credit',
            ]);
            $tracker->table('transactions_envelopes')->insert(['transaction_id' => $credit, 'envelope_id' => $id]);
        }
        if ($ownerId !== null) {
            $tracker->table('envelopes_accounts')->insert(['envelope_id' => $id, 'account_id' => $ownerId]);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function listedEnvelope(int $id): array
    {
        $codes = $this->getJson(self::BASE.'budget-codes', $this->admin())->assertOk()->json();
        $envelopes = collect($codes['unassigned'])->merge(collect($codes['data'])->flatMap(fn (array $code): array => $code['envelopes']));

        return $envelopes->firstWhere('id', (string) $id);
    }

    private function lastRowOf(int $accountId): object
    {
        return DB::connection('tracker')
            ->table('transactions_accounts as ta')
            ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
            ->where('ta.account_id', $accountId)
            ->orderByDesc('t.id')
            ->first(['t.*']);
    }

    /**
     * @return Collection<int, object>
     */
    private function transfersFrom(int $accountId): Collection
    {
        return DB::connection('tracker')->table('account_fund_transfers')->where('source_account_id', $accountId)->get();
    }

    private function accountRemaining(int $accountId): float
    {
        return $this->balance('transactions_accounts', 'account_id', $accountId);
    }

    private function envelopeRemaining(int $envelopeId): float
    {
        return $this->balance('transactions_envelopes', 'envelope_id', $envelopeId);
    }

    private function balance(string $links, string $column, int $id): float
    {
        $row = DB::connection('tracker')
            ->table($links.' as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.'.$column, $id)
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'Credit' THEN t.amount END), 0) - COALESCE(SUM(CASE WHEN t.type = 'Expense' THEN t.amount END), 0) AS remaining")
            ->first();

        return (float) $row->remaining;
    }

    private function logged(string $resource, string $fragment): bool
    {
        return PortalLog::query()->where('resource', $resource)->where('message', 'like', '%'.$fragment.'%')->exists();
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
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->json('token');
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
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }
}
