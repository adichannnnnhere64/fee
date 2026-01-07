<?php

namespace Repay\Fee\Services;

use Illuminate\Support\Facades\Cache;
use Repay\Fee\Contracts\UpcomingFeeInterface;
use Repay\Fee\Models\FeeRule;

class UpcomingFeeService implements UpcomingFeeInterface
{
    public function getLatestUpcomingFees($entity = null): array
    {
        $cacheKey = $this->getUpcomingCacheKey($entity);

        if (! config('fee.cache.enabled', true)) {
            return $this->resolveLatestUpcomingFees($entity);
        }

        return Cache::remember($cacheKey, config('fee.cache.ttl'), function () use ($entity) {
            return $this->resolveLatestUpcomingFees($entity);
        });
    }

    public function getUpcomingFeeForItemType(string $itemType, $entity = null): ?FeeRule
    {
        $fees = $this->getLatestUpcomingFees($entity);

        return $fees[$itemType] ?? null;
    }

    protected function resolveLatestUpcomingFees($entity = null): array
    {
        $result = [
            'product' => null,
            'service' => null,
        ];

        // Get upcoming product fee (always markup)
        $result['product'] = $this->getLatestUpcomingProductFee($entity);

        // Get upcoming service fee (either commission OR convenience)
        $result['service'] = $this->getLatestUpcomingServiceFee($entity);

        return $result;
    }

    protected function getLatestUpcomingProductFee($entity = null): ?FeeRule
    {
        $query = FeeRule::query()
            ->upcoming()
            ->forItemType('product')
            ->forFeeType('markup')
            ->orderBy('effective_from', 'asc')
            ->orderBy('created_at', 'desc');

        if ($entity) {
            // Try entity-specific first
            $entityFee = $query->clone()
                ->forEntity($entity)
                ->first();

            if ($entityFee) {
                return $entityFee;
            }
        }

        // Fall back to global
        return $query->clone()
            ->global()
            ->first();
    }

    protected function getLatestUpcomingServiceFee($entity = null): ?FeeRule
    {
        $query = FeeRule::query()
            ->upcoming()
            ->forItemType('service')
            ->orderBy('effective_from', 'asc')
            ->orderBy('created_at', 'desc');

        if ($entity) {
            // Try entity-specific first (commission, then convenience)
            $entityFee = $this->getLatestEntityServiceFee($entity, $query);

            if ($entityFee) {
                return $entityFee;
            }
        }

        // Fall back to global
        return $this->getLatestGlobalServiceFee($query);
    }

    protected function getLatestEntityServiceFee($entity, $baseQuery): ?FeeRule
    {
        // Clone the query for entity-specific
        $entityQuery = $baseQuery->clone()->forEntity($entity);

        // Try commission first
        $commissionFee = $entityQuery->clone()
            ->forFeeType('commission')
            ->first();

        if ($commissionFee) {
            return $commissionFee;
        }

        // Try convenience if no commission
        return $entityQuery->clone()
            ->forFeeType('convenience')
            ->first();
    }

    protected function getLatestGlobalServiceFee($baseQuery): ?FeeRule
    {
        $globalQuery = $baseQuery->clone()->global();

        // Try commission first
        $commissionFee = $globalQuery->clone()
            ->forFeeType('commission')
            ->first();

        if ($commissionFee) {
            return $commissionFee;
        }

        // Try convenience if no commission
        return $globalQuery->clone()
            ->forFeeType('convenience')
            ->first();
    }

    protected function getUpcomingCacheKey($entity = null): string
    {
        $prefix = config('fee.cache.prefix', 'fee_upcoming:');

        if ($entity) {
            return $prefix.'entity:'.get_class($entity).':'.$entity->getKey();
        }

        return $prefix.'global';
    }

    public function clearUpcomingCache($entity = null): void
    {
        Cache::forget($this->getUpcomingCacheKey($entity));
    }
}
