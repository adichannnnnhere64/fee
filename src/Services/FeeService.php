<?php

namespace Repay\Fee\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Repay\Fee\DTO\CreateFee;
use Repay\Fee\Models\FeeRule;

class FeeService
{
    public function getActiveFeeFor($entity, string $itemType): ?FeeRule
    {
        $cacheKey = $this->getCacheKey($entity, $itemType);

        return Cache::remember($cacheKey, config('fee.cache.ttl'), function () use ($entity, $itemType) {
            if ($entity) {
                $entityFee = FeeRule::forEntity($entity)
                    ->forItemType($itemType)
                    ->active()
                    ->first();

                if ($entityFee) {
                    return $entityFee;
                }
            }

            $globalFee = FeeRule::global()
                ->forItemType($itemType)
                ->active()
                ->first();

            if (! $globalFee) {
                return null;
            }

            if ($globalFee->apply_to_existing_entity) {
                $dateColumn = config('fee.entity_date_column', 'created_at');

                if (! $entity || ! isset($entity->{$dateColumn})) {
                    return null;
                }

                $entityDate = $entity->{$dateColumn};

                if (! $entityDate instanceof \Carbon\Carbon) {
                    $entityDate = \Illuminate\Support\Carbon::parse($entityDate);
                }

                if ($entityDate->gt($globalFee->created_at)) {
                    return null;
                }
            }

            return $globalFee;
        });
    }

    public function setFeeForEntity(array|CreateFee $data, $entity): FeeRule
    {
        // Convert array to DTO if needed
        $dto = $data instanceof CreateFee
            ? $data

            : CreateFee::fromArray($data);

        $itemType = $dto->itemType;

        // Find existing active fee for this entity and item type

        $existingFee = FeeRule::forEntity($entity)
            ->forItemType($itemType)
            ->active()
            ->first();

        // Deactivate any existing active fee
        if ($existingFee) {
            $existingFee->update(['is_active' => false]);
        }

        // Create new fee using DTO's database array
        $fee = FeeRule::create(array_merge($dto->toDatabaseArray(), [

            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),

            'is_global' => false,
        ]));

        $this->clearCacheForEntity($entity);

        // Log the change using DTO's reason
        $logReason = $dto->getReason();


        if ($existingFee) {
            $logReason = $existingFee->is_active ? 'Replaced active fee' : $logReason;
        }

        app('fee.history')->logChange(
            $fee,
            $existingFee?->toArray() ?? [],
            $logReason
        );

        return $fee;
    }

    public function createGlobalFee(array|CreateFee $data): FeeRule
    {
        // Convert array to DTO if needed
        $dto = $data instanceof CreateFee
            ? $data
            : CreateFee::fromArray($data);

        // Create fee using DTO's database array
        $fee = FeeRule::create(array_merge($dto->toDatabaseArray(), [
            'is_global' => true,
            'entity_type' => null,
            'entity_id' => null,

        ]));

        app('fee.history')->logChange(
            $fee,
            [],
            $dto->getReason()
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

        // Handle null entity
        if ($entity === null) {
            return $prefix.'global:'.$itemType;
        }

        // Handle object entity
        return $prefix.get_class($entity).':'.$entity->getKey().':'.$itemType;
    }
}
