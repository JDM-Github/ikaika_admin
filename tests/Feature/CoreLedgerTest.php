<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use App\Support\Core\CoreActionType;
use App\Support\Core\CoreLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CoreLedgerTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['core'];

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
}
