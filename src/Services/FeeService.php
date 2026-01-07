<?php

namespace Repay\Fee\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Repay\Fee\Models\FeeRule;

class FeeService
{
    public function getActiveFeeFor($entity, string $itemType): ?FeeRule
    {
        $cacheKey = $this->getCacheKey($entity, $itemType);

        return Cache::remember($cacheKey, config('fee.cache.ttl'), function () use ($entity, $itemType) {
            // Check for entity-specific fee
            $entityFee = FeeRule::forEntity($entity)
                ->forItemType($itemType)
                ->active()
                ->first();

            if ($entityFee) {
                return $entityFee;
            }

            // Fallback to global fee
            return FeeRule::global()
                ->forItemType($itemType)
                ->active()
                ->first();
        });
    }

    public function setFeeForEntity(array $data, $entity): FeeRule
    {
        $itemType = $data['item_type'];

        // Find existing active fee for this entity and item type
        $existingFee = FeeRule::forEntity($entity)
            ->forItemType($itemType)
            ->active()
            ->first();

        // Deactivate any existing active fee
        if ($existingFee) {
            $existingFee->update(['is_active' => false]);
        }

        // Create new fee
        $fee = FeeRule::create(array_merge($data, [
            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),
            'is_global' => false,
        ]));

        $this->clearCacheForEntity($entity);

        // Log the change
        if ($existingFee) {
            // This is an update/replacement
            app('fee.history')->logChange(
                $fee,
                $existingFee->toArray(),
                $existingFee->is_active ? 'Replaced active fee' : 'Created new fee'
            );
        } else {
            // This is a new fee creation
            app('fee.history')->logChange(
                $fee,
                [],
                'Created new fee'
            );
        }

        return $fee;
    }

    public function createGlobalFee(array $data): FeeRule
    {
        $fee = FeeRule::create(array_merge($data, [
            'is_global' => true,
            'entity_type' => null,
            'entity_id' => null,
        ]));

        // Log the creation
        app('fee.history')->logChange(
            $fee,
            [],
            'Created global fee'
        );

        return $fee;
    }

    public function calculateFor($entity, float $amount, string $itemType): array
    {
        $feeRule = $this->getActiveFeeFor($entity, $itemType);

        if (! $feeRule) {
            return [
                'amount' => $amount,
                'fee_amount' => 0,
                'total' => $amount,
                'has_fee' => false,
            ];
        }

        $feeAmount = $feeRule->calculate($amount);
        $total = $amount + $feeAmount;

        return [
            'amount' => $amount,
            'fee_amount' => $feeAmount,
            'total' => $total,
            'has_fee' => true,
            'fee_rule' => [
                'id' => $feeRule->id,
                'fee_type' => $feeRule->fee_type,
                'value' => $feeRule->value,
                'calculation_type' => $feeRule->calculation_type,
                'is_global' => $feeRule->is_global,
            ],
        ];
    }

    public function getAllActiveFeesFor($entity): Collection
    {
        $fees = collect();

        foreach (array_keys(config('fee.fee_types')) as $itemType) {
            if ($fee = $this->getActiveFeeFor($entity, $itemType)) {
                $fees->push($fee);
            }
        }

        return $fees;
    }

    public function getGlobalFees(): Collection
    {
        return FeeRule::global()
            ->active()
            ->get();
    }

    public function clearCacheForEntity($entity): void
    {
        foreach (array_keys(config('fee.fee_types')) as $itemType) {
            Cache::forget($this->getCacheKey($entity, $itemType));
        }
    }

    public function getCacheKey($entity, string $itemType): string
    {
        $prefix = config('fee.cache.prefix', 'fee_rules:');

        return $prefix.get_class($entity).':'.$entity->getKey().':'.$itemType;
    }
}
