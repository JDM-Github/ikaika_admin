<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CoreLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core', 'portal'];

    public function test_catalog_lists_core_actions_and_recycle(): void
    {
        $this->getJson('/api/development/core')
            ->assertOk()
            ->assertJsonPath('product', 'core')
            ->assertJsonPath('health.ok', true);

        $names = collect($this->getJson('/api/development/core')->json('resources'))
            ->pluck('name')
            ->all();
        $this->assertContains('actions', $names);
        $this->assertContains('recycle', $names);
    }

    public function test_add_clears_recycle_for_that_product_only(): void
    {
        $ledger = app(CoreLedger::class);
        $key = '12:2026-08-24-daily';

        $ledger->recycleDeleted(
            CoreLedger::PRODUCT_PORTAL,
            $key,
            'portal.user_reports',
            '2026-08-24-daily',
            ['id' => '2026-08-24-daily', 'lines' => []],
            'reports.submitted',
            12,
            '260701-0020',
        );
        $ledger->recycleDeleted(
            CoreLedger::PRODUCT_ESTIMATOR,
            $key,
            'project_estimator.projects',
            '2026-08-24-daily',
            ['id' => 'est-row'],
            'projects',
            99,
            null,
        );

        $this->assertSame(1, Recycle::query()->where('product', 'portal')->where('recycle_key', $key)->count());
        $this->assertSame(1, Recycle::query()->where('product', 'project-estimator')->where('recycle_key', $key)->count());

        $ledger->recordAdd(
            CoreLedger::PRODUCT_PORTAL,
            $key,
            'portal.user_reports',
            ['id' => '2026-08-24-daily'],
            'reports.submitted',
            '2026-08-24-daily',
            12,
            '260701-0020',
        );

        $this->assertSame(0, Recycle::query()->where('product', 'portal')->where('recycle_key', $key)->count());
        $this->assertSame(1, Recycle::query()->where('product', 'project-estimator')->where('recycle_key', $key)->count());

        $add = Action::query()
            ->where('product', 'portal')
            ->where('recycle_key', $key)
            ->where('action_type', CoreActionType::ADD)
            ->first();
        $this->assertNotNull($add);
        $this->assertSame('portal.user_reports', $add->database_target);
        $this->assertIsArray($add->parameters);
        $this->assertSame('add', $add->parameters['action_type'] ?? null);
        $this->assertSame('portal', $add->parameters['product'] ?? null);
    }

    public function test_within_rolls_back_the_product_write_and_the_ledger_together(): void
    {
        $ledger = app(CoreLedger::class);
        $portal = DB::connection('portal');
        $before = Action::query()->count();
        $recordId = null;

        try {
            $ledger->within($portal, function () use ($ledger, $portal, &$recordId): void {
                $recordId = (int) $portal->table('requests')->insertGetId([
                    'request_date' => '2026-09-01',
                    'name' => 'Ledger Rollback',
                    'status' => 'Pending',
                    'type' => 'Leave',
                ]);
                $ledger->recordAdd(
                    CoreLedger::PRODUCT_PORTAL,
                    '1:2026-09-01-leave',
                    'portal.requests',
                    ['id' => (string) $recordId],
                    'requests.leave',
                    (string) $recordId,
                    1,
                    null,
                );

                throw new RuntimeException('after both writes');
            });
            $this->fail('within() should rethrow.');
        } catch (RuntimeException $error) {
            $this->assertSame('after both writes', $error->getMessage());
        }

        // Neither side survives: a write can never land without its action, nor the reverse.
        $this->assertNotNull($recordId);
        $this->assertFalse($portal->table('requests')->where('id', $recordId)->exists());
        $this->assertSame($before, Action::query()->count());
    }

    public function test_stored_payloads_never_carry_secrets_or_government_ids(): void
    {
        $ledger = app(CoreLedger::class);

        $actionId = $ledger->recordEdit(
            CoreLedger::PRODUCT_PORTAL,
            'portal.employees',
            [
                'table' => 'employees',
                'password' => 'hunter2',
                'fields' => ['role' => 'Admin', 'sss_no' => '34-1234567-8'],
                'changes' => ['bank_account_number' => ['from' => '1', 'to' => '2']],
            ],
            'employees',
            '9',
        );

        $stored = Action::query()->findOrFail($actionId)->parameters['parameters'];
        $this->assertSame(['table' => 'employees', 'fields' => ['role' => 'Admin'], 'changes' => []], $stored);
    }
}
