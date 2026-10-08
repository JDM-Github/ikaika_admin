<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Charging an approved reimbursement to a budget envelope in the transaction tracker.
 *
 * Runs against the real tracker database inside a transaction, so the live envelopes are present
 * alongside the ones seeded here. Assertions name the seeded envelopes rather than totals, which the
 * live data would move.
 */
class PortalBudgetEnvelopesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'tracker', 'core'];

    private int $claimSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        // Said outright rather than inherited from .env, so the suite means the same thing anywhere.
        config(['products.catalog.transaction-tracker.enabled' => true]);
        // Claims follow manage.requests.reimbursement's reach, and the seeded teams sit in departments of their own.
        DB::connection('portal')->table('access_personal')->updateOrInsert(
            ['employee_id' => $this->approver()->getKey(), 'permission_key' => 'manage.requests.reimbursement'],
            ['reach' => 'all'],
        );
    }

    public function test_the_summary_lists_active_envelopes_with_their_balances(): void
    {
        $open = $this->envelope('Seeded Office Supplies', 1000.00);
        $closed = $this->envelope('Seeded Closed Envelope', 500.00, active: false);

        $response = $this->getJson('/api/development/portal/manage/budget-envelopes', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('currency', 'PHP');

        $envelopes = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($envelopes->has((string) $open));
        $this->assertFalse($envelopes->has((string) $closed));
        $this->assertEqualsWithDelta(1000.00, $envelopes[(string) $open]['funded'], 0.001);
        $this->assertEqualsWithDelta(0.00, $envelopes[(string) $open]['spent'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $envelopes[(string) $open]['remaining'], 0.001);

        // Remaining is allocated less spent, never a figure that fails to reconcile with the other two.
        $totals = $response->json('totals');
        $this->assertEqualsWithDelta($totals['allocated'] - $totals['spent'], $totals['remaining'], 0.001);
    }

    // The review's picker lays the envelopes out under their budget codes, so each one names its codes.
    public function test_the_summary_names_the_budget_codes_each_envelope_sits_under(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId([
            'name' => 'SEED-002-20310201',
            'description' => 'Seeded pantry restock',
            'transfer_date_received' => '2031-02-01',
            'expected_amount_quotation' => 3000.00,
        ]);
        $coded = $this->envelope('Seeded Coded Pantry', 1000.00);
        $loose = $this->envelope('Seeded Loose Pantry', 400.00);
        $tracker->table('envelopes_budget_codes')->insert(['envelope_id' => $coded, 'budget_code_id' => $code]);

        $response = $this->getJson('/api/development/portal/manage/budget-envelopes', $this->admin())
            ->assertOk();

        $envelopes = collect($response->json('data'))->keyBy('id');
        $this->assertSame(
            [['id' => (string) $code, 'name' => 'SEED-002-20310201', 'description' => 'Seeded pantry restock']],
            $envelopes[(string) $coded]['codes'],
        );
        $this->assertSame([], $envelopes[(string) $loose]['codes']);
    }

    public function test_approving_one_item_charges_only_that_item(): void
    {
        $envelope = $this->envelope('Seeded Team Amenities', 2000.00);
        [$first, $second] = $this->claim([['Paper towel', 300.00, 1], ['Air freshener', 150.00, 2]]);

        $this->decide([['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        // A claim is settled item by item: the air freshener, 150 x 2, while the paper towel waits.
        $charge = $this->chargeOfClaim($first);
        $this->assertNotNull($charge);
        $this->assertSame('Expense', $charge->type);
        $this->assertSame('300.00', $charge->amount);
        $this->assertSame((string) $envelope, (string) $charge->envelope_id);
        $this->assertEqualsWithDelta(1700.00, $this->remaining($envelope), 0.001);
        $this->assertSame(['Pending', 'Approved'], $this->statuses([$first, $second]));
    }

    // Nothing lands on an envelope the approver did not pick: not even the one the claim's other item went to.
    public function test_each_item_is_charged_to_the_envelope_picked_for_it(): void
    {
        $supplies = $this->envelope('Seeded Team Supplies', 2000.00);
        $pantry = $this->envelope('Seeded Pantry Corner', 1000.00);
        [$first, $second] = $this->claim([['Paper towel', 300.00, 1], ['Air freshener', 150.00, 2]]);
        $this->decide([['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $supplies]])
            ->assertOk();

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a budget envelope to charge this reimbursement to.');
        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $pantry]])
            ->assertOk();

        $this->assertSame(2, $this->chargeCountOfClaim($first));
        $this->assertSame('300.00', $this->chargeOfItem($first)->amount);
        $this->assertSame((string) $pantry, (string) $this->chargeOfItem($first)->envelope_id);
        $this->assertSame('300.00', $this->chargeOfItem($second)->amount);
        $this->assertSame((string) $supplies, (string) $this->chargeOfItem($second)->envelope_id);
        $this->assertEqualsWithDelta(1700.00, $this->remaining($supplies), 0.001);
        $this->assertEqualsWithDelta(700.00, $this->remaining($pantry), 0.001);
    }

    // The queue says which envelope funded each approved item; a pending one names none (supervisor, 2026-10-07).
    public function test_the_queue_names_the_envelope_each_approved_item_was_funded_from(): void
    {
        $supplies = $this->envelope('Seeded Team Supplies', 2000.00);
        [$first, $second, $third] = $this->claim([['Paper towel', 300.00, 1], ['Air freshener', 150.00, 2], ['Coffee', 90.00, 1]]);
        $pantry = $this->envelope('Seeded Pantry Corner', 1000.00);
        $this->decide([
            ['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $supplies],
            ['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $pantry],
        ])->assertOk();

        $items = collect($this->getJson('/api/development/portal/manage/requests?from=2026-09-01&to=2026-09-01', $this->admin())
            ->assertOk()
            ->json('reimbursements'))
            ->flatMap(static fn (array $claim): array => $claim['items'])
            ->keyBy('id');

        $this->assertSame('Seeded Team Supplies', $items[(string) $first]['envelopeName']);
        $this->assertSame('Seeded Pantry Corner', $items[(string) $second]['envelopeName']);
        $this->assertNull($items[(string) $third]['envelopeName']);
    }

    public function test_setting_one_approved_item_back_returns_only_its_own_charge(): void
    {
        $envelope = $this->envelope('Seeded Pantry Shelf', 1000.00);
        [$first, $second] = $this->claim([['Coffee', 400.00, 1], ['Sugar', 100.00, 1]]);
        $this->decide([
            ['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
            ['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
        ])->assertOk();
        $this->assertEqualsWithDelta(500.00, $this->remaining($envelope), 0.001);

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertNull($this->chargeOfItem($first));
        $this->assertSame('100.00', $this->chargeOfItem($second)->amount);
        $this->assertEqualsWithDelta(900.00, $this->remaining($envelope), 0.001);
        $this->assertSame(['Pending', 'Approved'], $this->statuses([$first, $second]));
    }

    public function test_an_items_overspend_is_checked_against_what_its_envelope_has_left(): void
    {
        $envelope = $this->envelope('Seeded Road Trip', 500.00);
        [$first, $second] = $this->claim([['Taxi', 300.00, 1], ['Toll', 300.00, 1]]);
        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $refused = $this->decide([['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]]);

        $refused->assertStatus(422);
        $this->assertStringContainsString('has ₱200.00 left, but this item is ₱300.00', (string) $refused->json('message'));
        $this->assertNull($this->chargeOfItem($second));
        $this->assertSame(['Approved', 'Pending'], $this->statuses([$first, $second]));

        $this->decide([[
            'id' => (string) $second,
            'kind' => 'reimbursement',
            'status' => 'approved',
            'envelopeId' => $envelope,
            'allowOverspend' => true,
        ]])->assertOk();

        $this->assertSame('300.00', $this->chargeOfItem($second)->amount);
        $this->assertEqualsWithDelta(-100.00, $this->remaining($envelope), 0.001);
    }

    // A closed envelope takes no new item, but an item set back still gives its charge back to it.
    public function test_a_closed_envelope_takes_back_an_item_but_takes_no_new_one(): void
    {
        $envelope = $this->envelope('Seeded Closing Envelope', 1000.00);
        [$first, $second] = $this->claim([['Glue', 100.00, 1], ['Tape', 50.00, 1]]);
        $this->decide([
            ['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
            ['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
        ])->assertOk();
        DB::connection('tracker')->table('envelopes_budget')->where('id', $envelope)->update(['active' => false]);

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();
        $this->assertNull($this->chargeOfItem($first));
        $this->assertSame('50.00', $this->chargeOfItem($second)->amount);

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Closing Envelope is not an active budget envelope.');
        $this->assertNull($this->chargeOfItem($first));
    }

    /*
     * Before items were charged one by one, the portal charged a claim whole. Setting one of that
     * claim's items back takes just its share out of that charge, and the rest stays where it is.
     */
    public function test_a_whole_claim_charge_from_before_gives_back_only_the_item_set_back(): void
    {
        $envelope = $this->envelope('Seeded Legacy Envelope', 1000.00);
        [$first, $second] = $this->claim([['Chair', 400.00, 1], ['Mat', 100.00, 1]]);
        DB::connection('portal')->table('reimbursements')->whereIn('id', [$first, $second])->update(['status' => 'Approved']);
        $this->unbookedCharge($first, $envelope, 500.00);

        $this->decide([['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertSame('400.00', $this->chargeOfItem($first)->amount);
        $this->assertEqualsWithDelta(600.00, $this->remaining($envelope), 0.001);
        $this->assertTrue(PortalLog::query()
            ->where('resource', 'manage.budget-envelopes')
            ->where('message', 'like', '%charge on Seeded Legacy Envelope from ₱500.00 to ₱400.00%')
            ->exists());

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertSame(0, $this->chargeCountOfClaim($first));
        $this->assertEqualsWithDelta(1000.00, $this->remaining($envelope), 0.001);
    }

    // Set back, the item carrying a whole-claim charge hands what is left to the claim's other item.
    public function test_a_whole_claim_charge_moves_off_the_item_that_carried_it(): void
    {
        $envelope = $this->envelope('Seeded Legacy Carrier', 1000.00);
        [$first, $second] = $this->claim([['Chair', 400.00, 1], ['Mat', 100.00, 1]]);
        DB::connection('portal')->table('reimbursements')->whereIn('id', [$first, $second])->update(['status' => 'Approved']);
        $this->unbookedCharge($first, $envelope, 500.00);

        $this->decide([['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertNull($this->chargeOfItem($first));
        $this->assertSame('100.00', $this->chargeOfItem($second)->amount);
        $this->assertEqualsWithDelta(900.00, $this->remaining($envelope), 0.001);
    }

    // The queue splits a claim by status; both halves carry the claim's key, and no charge at all.
    public function test_the_queue_gives_both_halves_of_a_split_claim_its_key(): void
    {
        $envelope = $this->envelope('Seeded Queue Envelope', 1000.00);
        [$first, $second] = $this->claim([['Soap', 120.00, 1], ['Brush', 80.00, 1]]);
        [$loose] = $this->claim([['Sponge', 40.00, 1]]);
        $this->decide([['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $listed = collect($this->getJson('/api/development/portal/manage/requests?from=2026-09-01&to=2026-09-01', $this->admin())
            ->assertOk()
            ->json('reimbursements'));
        $holding = fn (int $itemId): array => $listed->first(
            fn (array $claim): bool => in_array((string) $itemId, array_column($claim['items'], 'id'), true),
        );

        foreach ([$first, $second] as $itemId) {
            $this->assertSame((string) $first, $holding($itemId)['chargeKey']);
            $this->assertArrayNotHasKey('charge', $holding($itemId));
        }
        // One claim stays one row, each item with its own decision.
        $this->assertSame($holding($first)['id'], $holding($second)['id']);
        $statuses = array_column($holding($first)['items'], 'status', 'id');
        $this->assertSame(['pending', 'approved'], [$statuses[(string) $first], $statuses[(string) $second]]);
        $this->assertSame((string) $loose, $holding($loose)['chargeKey']);
    }

    // The reported ask: once approved, a line shows the envelope its money came from.
    public function test_the_queue_names_the_envelope_each_approved_item_was_paid_from(): void
    {
        $envelope = $this->envelope('Seeded Paying Envelope', 1000.00);
        [$waiting, $approved] = $this->claim([['Tape', 60.00, 1], ['Glue', 45.00, 2]]);
        $this->decide([['id' => (string) $approved, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $items = $this->queuedItems();
        $this->assertSame('Seeded Paying Envelope', $items[(string) $approved]['envelopeName']);
        $this->assertNull($items[(string) $waiting]['envelopeName']);

        // With the tracker off nothing was charged, so nothing is named.
        config(['products.catalog.transaction-tracker.enabled' => false]);
        Cache::flush();
        $this->assertNull($this->queuedItems()[(string) $approved]['envelopeName']);
    }

    // A whole-claim charge from before paid for every approved item of the claim, not just its carrier.
    public function test_the_queue_names_a_whole_claim_charges_envelope_on_each_approved_item(): void
    {
        $envelope = $this->envelope('Seeded Legacy Payer', 1000.00);
        [$carrier, $covered, $refused] = $this->claim([['Chair', 400.00, 1], ['Mat', 100.00, 1], ['Lamp', 90.00, 1]]);
        DB::connection('portal')->table('reimbursements')->whereIn('id', [$carrier, $covered])->update(['status' => 'Approved']);
        DB::connection('portal')->table('reimbursements')->where('id', $refused)->update(['status' => 'Rejected']);
        $this->unbookedCharge($carrier, $envelope, 500.00);

        $items = $this->queuedItems();

        $this->assertSame('Seeded Legacy Payer', $items[(string) $carrier]['envelopeName']);
        $this->assertSame('Seeded Legacy Payer', $items[(string) $covered]['envelopeName']);
        $this->assertNull($items[(string) $refused]['envelopeName']);
    }

    public function test_an_approval_without_an_envelope_is_refused_and_changes_nothing(): void
    {
        [$item] = $this->claim([['Monthly grocery', 1644.25, 1]]);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a budget envelope to charge this reimbursement to.');

        $this->assertNull($this->chargeOf($item));
        $this->assertSame(['Pending'], $this->statuses([$item]));
    }

    public function test_an_overspend_is_refused_until_the_approver_confirms_it(): void
    {
        $envelope = $this->envelope('Seeded Travel', 100.00);
        [$item] = $this->claim([['Bus fare', 250.00, 1]]);

        $refused = $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]]);
        $refused->assertStatus(422);
        $this->assertStringContainsString('has ₱100.00 left, but this item is ₱250.00', (string) $refused->json('message'));
        $this->assertNull($this->chargeOf($item));
        $this->assertSame(['Pending'], $this->statuses([$item]));

        $this->decide([[
            'id' => (string) $item,
            'kind' => 'reimbursement',
            'status' => 'approved',
            'envelopeId' => $envelope,
            'allowOverspend' => true,
        ]])->assertOk();

        $charge = $this->chargeOf($item);
        $this->assertNotNull($charge);
        $this->assertStringContainsString('Charged over the envelope', (string) $charge->description);
        $this->assertEqualsWithDelta(-150.00, $this->remaining($envelope), 0.001);
    }

    public function test_two_items_approved_in_one_save_are_each_charged(): void
    {
        $envelope = $this->envelope('Seeded Pantry', 5000.00);
        [$first, $second] = $this->claim([['Coffee', 400.00, 1], ['Sugar', 100.00, 1]]);

        $this->decide([
            ['id' => (string) $first, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
            ['id' => (string) $second, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope],
        ])->assertOk();

        $this->assertSame(2, $this->chargeCountOfClaim($first));
        $this->assertSame('400.00', $this->chargeOfItem($first)->amount);
        $this->assertSame('100.00', $this->chargeOfItem($second)->amount);
        $this->assertEqualsWithDelta(4500.00, $this->remaining($envelope), 0.001);
    }

    public function test_setting_an_approved_claim_back_to_pending_returns_its_charge(): void
    {
        $envelope = $this->envelope('Seeded Housekeeping', 1000.00);
        [$item] = $this->claim([['Mop', 350.00, 1]]);
        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();
        $this->assertEqualsWithDelta(650.00, $this->remaining($envelope), 0.001);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertNull($this->chargeOf($item));
        $this->assertEqualsWithDelta(1000.00, $this->remaining($envelope), 0.001);
        $this->assertTrue(PortalLog::query()
            ->where('resource', 'manage.budget-envelopes')
            ->where('message', 'like', '%returned ₱350.00 to Seeded Housekeeping%')
            ->exists());
    }

    public function test_an_inactive_envelope_cannot_be_charged(): void
    {
        $envelope = $this->envelope('Seeded Retired Envelope', 1000.00, active: false);
        [$item] = $this->claim([['Stapler', 90.00, 1]]);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Seeded Retired Envelope is not an active budget envelope.');

        $this->assertNull($this->chargeOf($item));
    }

    /*
     * The charge to the first envelope is written to the tracker before the second change fails.
     * Both databases have to roll back together, or the ledger keeps a charge for a claim that the
     * portal never approved.
     */
    public function test_a_refused_change_rolls_back_a_charge_already_written_in_the_same_save(): void
    {
        $first = $this->envelope('Seeded Envelope A', 5000.00);
        $second = $this->envelope('Seeded Envelope B', 500.00);
        [$one, $two] = $this->claim([['Chair', 1200.00, 1], ['Desk', 800.00, 1]]);

        $refused = $this->decide([
            ['id' => (string) $one, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $first],
            ['id' => (string) $two, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $second],
        ]);

        $refused->assertStatus(422);
        $this->assertStringContainsString('Seeded Envelope B has ₱500.00 left, but this item is ₱800.00', (string) $refused->json('message'));
        $this->assertNull($this->chargeOfItem($one));
        $this->assertEqualsWithDelta(5000.00, $this->remaining($first), 0.001);
        $this->assertSame(['Pending', 'Pending'], $this->statuses([$one, $two]));
    }

    public function test_the_charge_is_logged_with_its_envelope_and_amount(): void
    {
        $envelope = $this->envelope('Seeded Office Maintenance', 3000.00);
        [$item] = $this->claim([['Light bulbs', 120.00, 3]]);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $log = PortalLog::query()
            ->where('resource', 'manage.budget-envelopes')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('charged ₱360.00 to Seeded Office Maintenance', (string) $log->message);
        $this->assertSame((string) $envelope, $log->payload['envelopeId']);
        $this->assertEqualsWithDelta(2640.00, $log->payload['remainingAfter'], 0.001);
        $this->assertFalse($log->payload['overspent']);
    }

    public function test_with_the_tracker_switched_off_an_approval_charges_nothing(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);
        [$item] = $this->claim([['Printer ink', 780.00, 1]]);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved']])->assertOk();

        $this->assertNull($this->chargeOf($item));
        $this->assertSame(['Approved'], $this->statuses([$item]));
        $this->getJson('/api/development/portal/manage/budget-envelopes', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('totals', null);
    }

    public function test_an_approval_books_the_charge_to_the_claimants_team_account(): void
    {
        [$member, $account] = $this->teamHolder('Seeded Design');
        $envelope = $this->envelope('Seeded Design Supplies', 2000.00);
        [$item] = $this->claim([['Sketchbook', 250.00, 2]], $member);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $charge = $this->chargeOf($item);
        $this->assertNotNull($charge);
        $this->assertSame([$account], $this->bookedAccounts((int) $charge->id));
        $this->assertEqualsWithDelta(-500.00, $this->accountRemaining($account), 0.001);
        $log = PortalLog::query()
            ->where('resource', 'manage.budget-envelopes')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame((string) $account, $log->payload['accountId']);
        $this->assertSame('Seeded Design Account', $log->payload['account']);
        $this->assertSame('Seeded Design', $log->payload['team']);
    }

    public function test_a_claimant_whose_team_holds_no_account_is_charged_to_the_envelope_alone(): void
    {
        [$member] = $this->teamHolder('Seeded Unfunded Team', holdsAccount: false);
        $envelope = $this->envelope('Seeded Unfunded Supplies', 1000.00);
        [$item] = $this->claim([['Tape', 50.00, 1]], $member);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $charge = $this->chargeOf($item);
        $this->assertNotNull($charge);
        $this->assertSame([], $this->bookedAccounts((int) $charge->id));
        $this->assertEqualsWithDelta(950.00, $this->remaining($envelope), 0.001);
    }

    public function test_setting_an_approved_claim_back_to_pending_returns_it_to_the_team_account(): void
    {
        [$member, $account] = $this->teamHolder('Seeded Ops');
        $envelope = $this->envelope('Seeded Ops Supplies', 1000.00);
        [$item] = $this->claim([['Batteries', 300.00, 1]], $member);
        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();
        $chargeId = (int) $this->chargeOf($item)?->id;
        $this->assertEqualsWithDelta(-300.00, $this->accountRemaining($account), 0.001);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'pending']])->assertOk();

        $this->assertSame([], $this->bookedAccounts($chargeId));
        $this->assertEqualsWithDelta(0.00, $this->accountRemaining($account), 0.001);
    }

    /*
     * Charges written before a charge carried its team account are booked once, to the claimant's
     * team; running the command again books nothing more.
     */
    public function test_the_booking_command_books_an_earlier_charge_to_the_claimants_team_once(): void
    {
        [$member, $account] = $this->teamHolder('Seeded Finance');
        $envelope = $this->envelope('Seeded Petty Cash', 1000.00);
        [$item] = $this->claim([['Receipt book', 120.00, 1]], $member);
        $claimId = $this->claimIdOf($item);
        $chargeId = $this->unbookedCharge($claimId, $envelope, 120.00);

        $this->artisan('portal:book-team-charges')
            ->expectsOutputToContain(sprintf('Charge %d (claim %d): booked to Seeded Finance Account (Seeded Finance).', $chargeId, $claimId))
            ->assertExitCode(0);
        $this->assertSame([$account], $this->bookedAccounts($chargeId));

        $this->artisan('portal:book-team-charges')->assertExitCode(0);
        $this->assertSame([$account], $this->bookedAccounts($chargeId));
    }

    public function test_the_booking_command_leaves_a_charge_whose_team_holds_no_account(): void
    {
        [$member] = $this->teamHolder('Seeded Lonely Team', holdsAccount: false);
        $envelope = $this->envelope('Seeded Lonely Envelope', 1000.00);
        [$item] = $this->claim([['Glue', 40.00, 1]], $member);
        $claimId = $this->claimIdOf($item);
        $chargeId = $this->unbookedCharge($claimId, $envelope, 40.00);

        $this->artisan('portal:book-team-charges')
            ->expectsOutputToContain(sprintf('Charge %d (claim %d): no team account for Seeded Lonely Team, left as it was.', $chargeId, $claimId))
            ->assertExitCode(0);
        $this->assertSame([], $this->bookedAccounts($chargeId));
    }

    public function test_the_booking_command_does_nothing_with_the_tracker_switched_off(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);

        $this->artisan('portal:book-team-charges')
            ->expectsOutputToContain('The transaction tracker is switched off here')
            ->assertExitCode(0);
    }

    /*
     * The Budget Tracker tab's ledger: every transaction, newest first, with the envelope it sits in
     * and the team account it moved through.
     */
    public function test_the_ledger_lists_every_transaction_with_its_envelope_and_team(): void
    {
        [, $account] = $this->teamHolder('Seeded Ledger');
        $envelope = $this->envelope('Seeded Ledger Envelope', 1000.00, active: false);
        $expense = $this->ledgerTransaction([
            'transaction_name' => 'Printer ink',
            'transaction_date' => '2031-01-02 09:30:00',
            'amount' => 250.50,
            'type' => 'Expense',
            'payor_payee' => 'Seeded Supplier',
            'created_by' => 'Seeded Clerk',
        ], $envelope, $account);
        $charge = $this->ledgerTransaction([
            'transaction_name' => 'Reimbursement',
            'transaction_date' => '2031-01-01 08:00:00',
            'amount' => 99.00,
            'type' => 'Expense',
            'source_key' => 'portal:reimbursement-claim:999999',
        ]);

        $data = $this->getJson('/api/development/portal/manage/budget-transactions', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('currency', 'PHP')
            ->json('data');

        $this->assertSame([(string) $expense, (string) $charge], [$data[0]['id'], $data[1]['id']]);
        $row = $data[0];
        $this->assertSame('Printer ink', $row['name']);
        $this->assertSame('2031-01-02', $row['date']);
        $this->assertSame('Expense', $row['type']);
        $this->assertEqualsWithDelta(250.50, $row['amount'], 0.001);
        $this->assertSame('Seeded Supplier', $row['payorPayee']);
        // A closed envelope's history is still part of the ledger.
        $this->assertSame(['Seeded Ledger Envelope'], $row['envelopes']);
        $this->assertSame('Seeded Ledger Account', $row['accountName']);
        $this->assertSame('Seeded Ledger', $row['team']);
        $this->assertSame('Seeded Clerk', $row['recordedBy']);
        $this->assertFalse($row['isPortalCharge']);
        $this->assertTrue($data[1]['isPortalCharge']);
        $this->assertSame([], $data[1]['envelopes']);
        $this->assertNull($data[1]['accountName']);
    }

    public function test_the_ledger_is_empty_with_the_tracker_switched_off(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);

        $this->getJson('/api/development/portal/manage/budget-transactions', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('teams', [])
            ->assertJsonPath('data', []);
    }

    // What each team has left: its account's Credits less its Expenses, summed from the same ledger.
    public function test_the_ledger_carries_each_active_team_account_balance(): void
    {
        [, $account] = $this->teamHolder('Seeded Balance Team');
        $this->ledgerTransaction(['transaction_name' => 'Funds Received', 'transaction_date' => '2031-02-01 00:00:00', 'amount' => 5000.00, 'type' => 'Credit'], null, $account);
        $this->ledgerTransaction(['transaction_name' => 'Supplies', 'transaction_date' => '2031-02-02 00:00:00', 'amount' => 1250.50, 'type' => 'Expense'], null, $account);
        $closed = $this->trackerAccount('Seeded Closed Balance', 'closed.balance@example.test', active: false);

        $teams = collect($this->getJson('/api/development/portal/manage/budget-transactions', $this->admin())
            ->assertOk()
            ->json('teams'))->keyBy('accountId');

        $team = $teams[(string) $account];
        $this->assertSame('Seeded Balance Team', $team['team']);
        $this->assertSame('Seeded Balance Team Account', $team['accountName']);
        $this->assertEqualsWithDelta(5000.00, $team['received'], 0.001);
        $this->assertEqualsWithDelta(1250.50, $team['spent'], 0.001);
        $this->assertEqualsWithDelta(3749.50, $team['remaining'], 0.001);
        $this->assertFalse($teams->has((string) $closed));
    }

    /*
     * The Envelopes tab: every envelope, open or closed, under the budget code it was raised for,
     * with its balance -- and the ones raised under no code on their own.
     */
    public function test_the_budget_codes_list_every_envelope_under_its_code(): void
    {
        $tracker = DB::connection('tracker');
        $code = (int) $tracker->table('budget_codes')->insertGetId([
            'name' => 'SEED-001-20310101',
            'description' => 'Seeded workstation package',
            'transfer_date_received' => '2031-01-01',
            'expected_amount_quotation' => 12000.00,
        ]);
        $open = $this->envelope('Seeded Coded Monitor', 5000.00);
        $closed = $this->envelope('Seeded Coded Keyboard', 1500.00, active: false);
        $loose = $this->envelope('Seeded Loose Envelope', 700.00);
        $tracker->table('envelopes_budget_codes')->insert([
            ['envelope_id' => $open, 'budget_code_id' => $code],
            ['envelope_id' => $closed, 'budget_code_id' => $code],
        ]);
        $this->ledgerTransaction(['transaction_name' => 'Monitor', 'transaction_date' => '2031-01-05 00:00:00', 'amount' => 1200.00, 'type' => 'Expense'], $open);

        $response = $this->getJson('/api/development/portal/manage/budget-codes', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', true);

        $seeded = collect($response->json('data'))->firstWhere('id', (string) $code);
        $this->assertSame('SEED-001-20310101', $seeded['name']);
        $this->assertSame('Seeded workstation package', $seeded['description']);
        $this->assertSame('2031-01-01', $seeded['receivedOn']);
        $this->assertEqualsWithDelta(12000.00, $seeded['expectedAmount'], 0.001);
        $envelopes = collect($seeded['envelopes'])->keyBy('id');
        $this->assertEqualsWithDelta(3800.00, $envelopes[(string) $open]['remaining'], 0.001);
        $this->assertTrue($envelopes[(string) $open]['active']);
        $this->assertFalse($envelopes[(string) $closed]['active']);
        $unassigned = collect($response->json('unassigned'))->pluck('id');
        $this->assertContains((string) $loose, $unassigned);
        $this->assertNotContains((string) $open, $unassigned);
    }

    public function test_the_budget_codes_are_empty_with_the_tracker_switched_off(): void
    {
        config(['products.catalog.transaction-tracker.enabled' => false]);

        $this->getJson('/api/development/portal/manage/budget-codes', $this->admin())
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('data', [])
            ->assertJsonPath('unassigned', []);
    }

    public function test_only_an_admin_reads_the_budget_codes(): void
    {
        $this->getJson('/api/development/portal/manage/budget-codes', [
            'Authorization' => 'Bearer '.$this->loginToken($this->activeMember()),
        ])->assertForbidden();
    }

    public function test_only_an_admin_reads_the_ledger(): void
    {
        $this->getJson('/api/development/portal/manage/budget-transactions', [
            'Authorization' => 'Bearer '.$this->loginToken($this->activeMember()),
        ])->assertForbidden();
    }

    // A closed account keeps its history but takes no new charges, even from its own team.
    public function test_a_closed_team_account_is_not_charged(): void
    {
        [$member, $account] = $this->teamHolder('Seeded Closed Team');
        DB::connection('tracker')->table('accounts')->where('id', $account)->update(['active' => false]);
        $envelope = $this->envelope('Seeded Closed Team Supplies', 1000.00);
        [$item] = $this->claim([['Pens', 60.00, 1]], $member);

        $this->decide([['id' => (string) $item, 'kind' => 'reimbursement', 'status' => 'approved', 'envelopeId' => $envelope]])
            ->assertOk();

        $this->assertSame([], $this->bookedAccounts((int) $this->chargeOf($item)?->id));
    }

    public function test_only_an_admin_reads_the_envelopes(): void
    {
        $this->getJson('/api/development/portal/manage/budget-envelopes', [
            'Authorization' => 'Bearer '.$this->loginToken($this->activeMember()),
        ])->assertForbidden();
    }

    private function envelope(string $name, float $funded, bool $active = true): int
    {
        $tracker = DB::connection('tracker');
        $id = (int) $tracker->table('envelopes_budget')->insertGetId([
            'envelope_name' => $name,
            'budget_amount' => $funded,
            'group_name' => 'Maintenance',
            'time_period' => 'Monthly',
            'active' => $active,
        ]);
        $credit = (int) $tracker->table('transactions')->insertGetId([
            'transaction_name' => 'Credit',
            'transaction_date' => '2026-09-01 00:00:00',
            'amount' => $funded,
            'type' => 'Credit',
        ]);
        $tracker->table('transactions_envelopes')->insert(['transaction_id' => $credit, 'envelope_id' => $id]);

        return $id;
    }

    /**
     * One claim: every row shares a date_created and a name, which is what makes them one claim.
     *
     * @param  list<array{0: string, 1: float, 2: int}>  $items
     * @return list<int>
     */
    private function claim(array $items, ?Employee $member = null): array
    {
        $member ??= $this->activeMember();
        // Years out and sequenced, so a seeded claim can never merge with a real one.
        $createdAt = Carbon::create(2031, 1, 1, 9)->addMinutes(++$this->claimSequence)->format('Y-m-d H:i:s');
        $ids = [];
        foreach ($items as [$label, $cost, $qty]) {
            $id = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
                'reimb_date' => '2026-09-01',
                'item' => $label,
                'cost' => $cost,
                'qty' => $qty,
                'purpose' => 'seeded',
                'employee_name_input' => $this->memberName($member),
                'team' => 'Angeles Pampanga Office',
                'status' => 'Pending',
                'date_created' => $createdAt,
            ]);
            DB::connection('portal')->table('employees_reimbursements')->insert([
                'employee_id' => $member->getKey(),
                'reimbursement_id' => $id,
            ]);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * A member of a team of their own, and -- unless told otherwise -- that team's account in the
     * tracker, held in their name the way the live accounts are. No id_no, so no other helper picks
     * them as the member or the admin.
     *
     * @return array{0: Employee, 1: int|null}
     */
    private function teamHolder(string $team, bool $holdsAccount = true): array
    {
        $email = strtolower(str_replace(' ', '.', $team)).'.holder@example.test';
        $id = (int) DB::connection('portal')->table('employees')->insertGetId([
            'first_name' => 'Holder',
            'last_name' => $team,
            'email' => $email,
            'status' => 'Active',
            'role' => 'user',
            'department' => $team,
        ]);
        $member = Employee::query()->findOrFail($id);

        return [$member, $holdsAccount ? $this->trackerAccount($team.' Account', $email) : null];
    }

    private function trackerAccount(string $name, string $email, bool $active = true): int
    {
        return (int) DB::connection('tracker')->table('accounts')->insertGetId([
            'account_name' => $name,
            'email' => $email,
            'active' => $active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function ledgerTransaction(array $columns, ?int $envelopeId = null, ?int $accountId = null): int
    {
        $tracker = DB::connection('tracker');
        $id = (int) $tracker->table('transactions')->insertGetId($columns);
        if ($envelopeId !== null) {
            $tracker->table('transactions_envelopes')->insert(['transaction_id' => $id, 'envelope_id' => $envelopeId]);
        }
        if ($accountId !== null) {
            $tracker->table('transactions_accounts')->insert(['transaction_id' => $id, 'account_id' => $accountId]);
        }

        return $id;
    }

    // A charge as the portal wrote it before charges carried a team account: envelope only.
    /**
     * Every reimbursement line in the queue on the seeded claims' day, keyed by its item id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function queuedItems(): array
    {
        $claims = $this->getJson('/api/development/portal/manage/requests?from=2026-09-01&to=2026-09-01', $this->admin())
            ->assertOk()
            ->json('reimbursements');

        return collect($claims)->flatMap(fn (array $claim): array => $claim['items'])->keyBy('id')->all();
    }

    private function unbookedCharge(int $claimId, int $envelopeId, float $amount): int
    {
        $tracker = DB::connection('tracker');
        $id = (int) $tracker->table('transactions')->insertGetId([
            'transaction_name' => 'Reimbursement',
            'transaction_date' => '2026-09-23 00:00:00',
            'amount' => $amount,
            'type' => 'Expense',
            'source_key' => 'portal:reimbursement-claim:'.$claimId,
        ]);
        $tracker->table('transactions_envelopes')->insert(['transaction_id' => $id, 'envelope_id' => $envelopeId]);

        return $id;
    }

    /**
     * @return list<int>
     */
    private function bookedAccounts(int $transactionId): array
    {
        return DB::connection('tracker')
            ->table('transactions_accounts')
            ->where('transaction_id', $transactionId)
            ->orderBy('account_id')
            ->pluck('account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function accountRemaining(int $accountId): float
    {
        $row = DB::connection('tracker')
            ->table('transactions_accounts as ta')
            ->join('transactions as t', 't.id', '=', 'ta.transaction_id')
            ->where('ta.account_id', $accountId)
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'Credit' THEN t.amount END), 0) - COALESCE(SUM(CASE WHEN t.type = 'Expense' THEN t.amount END), 0) AS remaining")
            ->first();

        return (float) $row->remaining;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function decide(array $changes): TestResponse
    {
        return $this->patchJson('/api/development/portal/manage/requests', ['changes' => $changes], $this->admin());
    }

    private function chargeOf(int $itemId): ?object
    {
        return DB::connection('tracker')
            ->table('transactions as t')
            ->leftJoin('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->where('t.source_key', 'portal:reimbursement-claim:'.$this->claimIdOf($itemId))
            ->first(['t.*', 'te.envelope_id']);
    }

    // An item's own charge: the one that carries its id.
    private function chargeOfItem(int $itemId): ?object
    {
        return DB::connection('tracker')
            ->table('transactions as t')
            ->leftJoin('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->where('t.source_key', 'portal:reimbursement-claim:'.$itemId)
            ->first(['t.*', 'te.envelope_id']);
    }

    // A claim's charge, found under whichever of its items carries it.
    private function chargeOfClaim(int $itemId): ?object
    {
        return DB::connection('tracker')
            ->table('transactions as t')
            ->leftJoin('transactions_envelopes as te', 'te.transaction_id', '=', 't.id')
            ->whereIn('t.source_key', $this->claimSourceKeys($itemId))
            ->first(['t.*', 'te.envelope_id']);
    }

    private function chargeCountOfClaim(int $itemId): int
    {
        return DB::connection('tracker')
            ->table('transactions')
            ->whereIn('source_key', $this->claimSourceKeys($itemId))
            ->count();
    }

    /**
     * @return list<string>
     */
    private function claimSourceKeys(int $itemId): array
    {
        $seed = DB::connection('portal')->table('reimbursements')->where('id', $itemId)->first();

        return DB::connection('portal')
            ->table('reimbursements')
            ->where('date_created', $seed->date_created)
            ->where('employee_name_input', $seed->employee_name_input)
            ->pluck('id')
            ->map(fn (mixed $id): string => 'portal:reimbursement-claim:'.$id)
            ->all();
    }

    // The claim's identity is its lowest row id -- the same one the queue gives it.
    private function claimIdOf(int $itemId): int
    {
        $seed = DB::connection('portal')->table('reimbursements')->where('id', $itemId)->first();

        return (int) DB::connection('portal')
            ->table('reimbursements')
            ->where('date_created', $seed->date_created)
            ->where('employee_name_input', $seed->employee_name_input)
            ->min('id');
    }

    private function remaining(int $envelopeId): float
    {
        $row = DB::connection('tracker')
            ->table('transactions_envelopes as te')
            ->join('transactions as t', 't.id', '=', 'te.transaction_id')
            ->where('te.envelope_id', $envelopeId)
            ->selectRaw("COALESCE(SUM(CASE WHEN t.type = 'Credit' THEN t.amount END), 0) - COALESCE(SUM(CASE WHEN t.type = 'Expense' THEN t.amount END), 0) AS remaining")
            ->first();

        return (float) $row->remaining;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function statuses(array $ids): array
    {
        return DB::connection('portal')
            ->table('reimbursements')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->pluck('status')
            ->all();
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

    private function memberName(Employee $actor): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
    }
}
