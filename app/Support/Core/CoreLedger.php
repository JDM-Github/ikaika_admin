<?php

namespace App\Support\Core;

use App\Modules\Core\Models\Action;
use App\Modules\Core\Models\Recycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Core.actions + core.recycle. Every product database write goes through here.
 *
 * `product` is the catalog key (portal, project-estimator). Recycle uniqueness
 * is (product, recycle_key), so the same date key can exist in both bins.
 */
final class CoreLedger
{
    public const PRODUCT_PORTAL = 'portal';

    public const PRODUCT_ESTIMATOR = 'project-estimator';

    public const DEFAULT_RETAIN_DAYS = 30;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function recordEdit(
        string $product,
        string $databaseTarget,
        array $parameters,
        ?string $resource = null,
        ?string $recordId = null,
        ?string $recycleKey = null,
        ?int $actorId = null,
        ?string $actorIdNo = null,
    ): int {
        return $this->insertAction(
            $product,
            $databaseTarget,
            CoreActionType::EDIT,
            $parameters,
            $resource,
            $recordId,
            $recycleKey,
            $actorId,
            $actorIdNo,
        );
    }

    /**
     * Snapshot into recycle and log a delete action. Same product + key replaces the previous trash.
     *
     * @param  array<string, mixed>  $payload
     * @return array{recycle_id: int, action_id: int}
     */
    public function recycleDeleted(
        string $product,
        string $recycleKey,
        string $databaseTarget,
        string $recordId,
        array $payload,
        ?string $resource = null,
        ?int $actorId = null,
        ?string $actorIdNo = null,
    ): array {
        $this->assertProduct($product);
        $recycleKey = trim($recycleKey);
        if ($recycleKey === '') {
            throw new InvalidArgumentException('Recycle key is required.');
        }

        return DB::connection('core')->transaction(function () use (
            $product,
            $recycleKey,
            $databaseTarget,
            $recordId,
            $payload,
            $resource,
            $actorId,
            $actorIdNo,
        ): array {
            Recycle::query()
                ->where('product', $product)
                ->where('recycle_key', $recycleKey)
                ->delete();

            $recycleId = (int) Recycle::query()->insertGetId([
                'product' => $product,
                'recycle_key' => $recycleKey,
                'database_target' => $databaseTarget,
                'resource' => $resource,
                'record_id' => $recordId,
                'payload' => $this->json($payload),
                'deleted_by' => $actorId,
                'deleted_by_id_no' => $actorIdNo,
                'purges_at' => $this->purgesAt($product),
                'created_at' => Carbon::now(),
            ]);

            $actionId = $this->insertAction(
                $product,
                $databaseTarget,
                CoreActionType::DELETE,
                $payload,
                $resource,
                $recordId,
                $recycleKey,
                $actorId,
                $actorIdNo,
            );

            return ['recycle_id' => $recycleId, 'action_id' => $actionId];
        });
    }

    /**
     * A new live record occupies this key, so restoring the old one no longer makes sense.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function recordAdd(
        string $product,
        string $recycleKey,
        string $databaseTarget,
        array $parameters,
        ?string $resource = null,
        ?string $recordId = null,
        ?int $actorId = null,
        ?string $actorIdNo = null,
    ): int {
        $this->assertProduct($product);

        return DB::connection('core')->transaction(function () use (
            $product,
            $recycleKey,
            $databaseTarget,
            $parameters,
            $resource,
            $recordId,
            $actorId,
            $actorIdNo,
        ): int {
            Recycle::query()
                ->where('product', $product)
                ->where('recycle_key', $recycleKey)
                ->delete();

            return $this->insertAction(
                $product,
                $databaseTarget,
                CoreActionType::ADD,
                $parameters,
                $resource,
                $recordId,
                $recycleKey,
                $actorId,
                $actorIdNo,
            );
        });
    }

    public function revert(int $recycleId, int $actionId): void
    {
        DB::connection('core')->transaction(function () use ($recycleId, $actionId): void {
            Recycle::query()->where('id', $recycleId)->delete();
            Action::query()->where('id', $actionId)->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function insertAction(
        string $product,
        string $databaseTarget,
        string $actionType,
        array $parameters,
        ?string $resource,
        ?string $recordId,
        ?string $recycleKey,
        ?int $actorId,
        ?string $actorIdNo,
    ): int {
        $this->assertProduct($product);
        $this->assertActionType($actionType);

        $envelope = [
            'database_target' => $databaseTarget,
            'product' => $product,
            'action_type' => $actionType,
            'resource' => $resource,
            'record_id' => $recordId,
            'recycle_key' => $recycleKey,
            'actor_id' => $actorId,
            'parameters' => $parameters,
        ];

        return (int) Action::query()->insertGetId([
            'product' => $product,
            'database_target' => $databaseTarget,
            'action_type' => $actionType,
            'resource' => $resource,
            'record_id' => $recordId,
            'recycle_key' => $recycleKey,
            'actor_id' => $actorId,
            'actor_id_no' => $actorIdNo,
            'parameters' => $this->json($envelope),
            'synced_at' => null,
            'created_at' => Carbon::now(),
        ]);
    }

    private function purgesAt(string $product): Carbon
    {
        $row = DB::connection('core')
            ->table('settings')
            ->select(['setting_value'])
            ->where('product', $product)
            ->where('setting_key', 'recycle.retain_days')
            ->first();

        $days = self::DEFAULT_RETAIN_DAYS;
        if ($row !== null && is_numeric($row->setting_value)) {
            $days = max(1, (int) $row->setting_value);
        }

        return Carbon::now()->addDays($days);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Action parameters could not be encoded.');
        }
    }

    private function assertProduct(string $product): void
    {
        $known = [self::PRODUCT_PORTAL, self::PRODUCT_ESTIMATOR];
        if (! in_array($product, $known, true)) {
            throw new InvalidArgumentException('Unknown core product ['.$product.'].');
        }
    }

    private function assertActionType(string $actionType): void
    {
        if (! in_array($actionType, CoreActionType::all(), true)) {
            throw new InvalidArgumentException('Unknown action type ['.$actionType.'].');
        }
    }
}
