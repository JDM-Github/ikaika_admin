<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Finance / Transactions' payor list (2026-10-21): the ledger's names, the most recently used first and then
 * the most used, a name added by hand kept at once and put first, and one taken off the list hidden even while
 * old transactions still carry it.
 *
 * Runs against the real tracker database inside a transaction, so the seeded names sit among live ones.
 */
class PortalBudgetPayorsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'tracker', 'core'];

    private const BASE = '/api/development/portal/manage/budget-payors';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['products.catalog.transaction-tracker.enabled' => true]);
    }

    public function test_the_list_is_the_ledgers_names_most_used_first_and_a_name_added_by_hand_joins_it_at_once(): void
    {
        $this->line('SEED-PAYOR Zeta');
        $this->line('SEED-PAYOR Zeta');
        $this->line('SEED-PAYOR Alpha');
        $headers = $this->admin();

        $names = $this->names($this->getJson(self::BASE, $headers)->assertOk()->assertJsonPath('resource', 'budget-payors'));
        $this->assertLessThan(array_search('SEED-PAYOR Alpha', $names, true), array_search('SEED-PAYOR Zeta', $names, true));

        $added = $this->names($this->postJson(self::BASE, ['name' => '  SEED-PAYOR New Supplier '], $headers)->assertCreated());
        $this->assertContains('SEED-PAYOR New Supplier', $added);
        $this->assertContains('SEED-PAYOR New Supplier', $this->names($this->getJson(self::BASE, $headers)->assertOk()));
        $this->assertTrue(PortalLog::query()->where('resource', 'manage.budget-payors')->where('message', 'like', '%SEED-PAYOR New Supplier%')->exists());
    }

    // The reported miss (2026-10-21): a name just added sat past the first few the form shows, so it could not be picked again.
    public function test_the_name_used_or_added_most_recently_comes_first(): void
    {
        $this->line('SEED-PAYOR Often');
        $this->line('SEED-PAYOR Often');
        $headers = $this->admin();

        $this->assertSame('SEED-PAYOR Brand New', $this->names($this->postJson(self::BASE, ['name' => 'SEED-PAYOR Brand New'], $headers)->assertCreated())[0]);
        $this->assertSame('SEED-PAYOR Brand New', $this->names($this->getJson(self::BASE, $headers)->assertOk())[0]);

        // A line saved under a name brings that name up the same way.
        $this->line('SEED-PAYOR Often', now('UTC')->addMinute()->format('Y-m-d H:i:s'));
        $this->assertSame('SEED-PAYOR Often', $this->names($this->getJson(self::BASE, $headers)->assertOk())[0]);
    }

    public function test_a_name_taken_off_the_list_stays_off_until_it_is_added_again(): void
    {
        $this->line('SEED-PAYOR Old Vendor');
        $headers = $this->admin();

        $this->assertNotContains('SEED-PAYOR Old Vendor', $this->names($this->postJson(self::BASE.'/remove', ['name' => 'seed-payor old vendor'], $headers)->assertOk()));
        $this->assertNotContains('SEED-PAYOR Old Vendor', $this->names($this->getJson(self::BASE, $headers)->assertOk()));
        // Only the list loses it: the transaction keeps the name it was saved with.
        $this->assertSame(1, DB::connection('tracker')->table('transactions')->where('payor_payee', 'SEED-PAYOR Old Vendor')->count());

        $this->assertContains('SEED-PAYOR Old Vendor', $this->names($this->postJson(self::BASE, ['name' => 'SEED-PAYOR Old Vendor'], $headers)->assertCreated()));
    }

    public function test_a_blank_name_is_refused_and_a_member_reaches_none_of_it(): void
    {
        $this->postJson(self::BASE, ['name' => '   '], $this->admin())->assertStatus(422);

        $member = ['Authorization' => 'Bearer '.$this->loginToken($this->activeMember())];
        $this->getJson(self::BASE, $member)->assertForbidden();
        $this->postJson(self::BASE, ['name' => 'SEED-PAYOR Stranger'], $member)->assertForbidden();
        $this->postJson(self::BASE.'/remove', ['name' => 'SEED-PAYOR Stranger'], $member)->assertForbidden();
    }

    private function line(string $payor, ?string $createdAt = null): void
    {
        DB::connection('tracker')->table('transactions')->insert([
            'transaction_name' => 'Seeded payor line',
            'transaction_date' => '2026-09-01 00:00:00',
            'amount' => 10,
            'type' => 'Expense',
            'payor_payee' => $payor,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        return array_column((array) $response->json('data'), 'name');
    }

    /**
     * @return array<string, string>
     */
    private function admin(): array
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->firstOrFail();

        return ['Authorization' => 'Bearer '.$this->loginToken($employee)];
    }

    private function activeMember(): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->firstOrFail();
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
