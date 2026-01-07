<?php

namespace Repay\Fee\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Repay\Fee\Contracts\FeeHistoryInterface;
use Repay\Fee\Models\FeeHistory;
use Repay\Fee\Models\FeeRule;

class FeeHistoryService implements FeeHistoryInterface
{
    public function getForEntity($entity, array $filters = []): array
    {
        $cacheKey = $this->getEntityHistoryCacheKey($entity, $filters);

        if (! config('fee.cache.enabled', true)) {
            return $this->queryEntityHistory($entity, $filters)->paginate(
                $filters['per_page'] ?? 15
            )->toArray();
        }

        return Cache::remember($cacheKey, config('fee.cache.ttl'), function () use ($entity, $filters) {
            return $this->queryEntityHistory($entity, $filters)->paginate(
                $filters['per_page'] ?? 15
            )->toArray();
        });
    }

    public function getGlobal(array $filters = []): array
    {
        $cacheKey = $this->getGlobalHistoryCacheKey($filters);

        if (! config('fee.cache.enabled', true)) {
            return $this->queryGlobalHistory($filters)->paginate(
                $filters['per_page'] ?? 15
            )->toArray();
        }

        return Cache::remember($cacheKey, config('fee.cache.ttl'), function () use ($filters) {
            return $this->queryGlobalHistory($filters)->paginate(
                $filters['per_page'] ?? 15
            )->toArray();
        });
    }

    public function logChange(FeeRule $feeRule, array $oldData, string $reason): void
    {
        FeeHistory::create([
            'fee_rule_id' => $feeRule->id,
            'entity_type' => $feeRule->entity_type,
            'entity_id' => $feeRule->entity_id,
            'action' => $oldData ? 'updated' : 'created',
            'old_data' => $oldData,
            'new_data' => $feeRule->toArray(),
            'reason' => $reason,
        ]);

        $this->clearHistoryCache($feeRule);
    }

    public function clearHistoryCacheForEntityTypeAndId(string $entityType, $entityId): void
    {
        $cacheKey = $this->getEntityHistoryCacheKeyByTypeAndId($entityType, $entityId, []);
        Cache::forget($cacheKey);
    }

    // Add this helper method if it doesn't exist
    protected function getEntityHistoryCacheKeyByTypeAndId(string $entityType, $entityId, array $filters): string
    {
        $prefix = config('fee.cache.prefix', 'fee_history:');
        $filterHash = md5(serialize($filters));

        return $prefix.'entity:'.$entityType.':'.$entityId.':'.$filterHash;
    }

    public function clearGlobalHistoryCache(): void
    {
        Cache::forget($this->getGlobalHistoryCacheKey([]));
    }

    protected function queryEntityHistory($entity, array $filters = []): Builder
    {
        $query = FeeHistory::where('entity_type', get_class($entity))
            ->where('entity_id', $entity->getKey())
            ->with('feeRule')
            ->orderBy('created_at', 'desc');

        return $this->applyFilters($query, $filters);
    }

    protected function queryGlobalHistory(array $filters = []): Builder
    {
        $query = FeeHistory::whereNull('entity_id')
            ->with('feeRule')
            ->orderBy('created_at', 'desc');

        return $this->applyFilters($query, $filters);
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        // Example filter: by item_type
        if (isset($filters['item_type'])) {
            $query->whereHas('feeRule', function ($q) use ($filters) {
                $q->where('item_type', $filters['item_type']);
            });
        }

        // Example filter: by fee_type
        if (isset($filters['fee_type'])) {
            $query->whereHas('feeRule', function ($q) use ($filters) {
                $q->where('fee_type', $filters['fee_type']);
            });
        }

        // Example filter: date range
        if (isset($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        // Add more filters here as needed in the future

        return $query;
    }

    public function clearHistoryCache(FeeRule $feeRule): void
    {
        if ($feeRule->entity_id) {
            // We need the entity class and ID, not the fee rule
            // Since we don't have the entity instance, we'll construct the cache key manually
            $prefix = config('fee.cache.prefix', 'fee_history:');
            $cacheKey = $prefix.'entity:'.$feeRule->entity_type.':'.$feeRule->entity_id.':'.md5(serialize([]));
            Cache::forget($cacheKey);
        } else {
            Cache::forget($this->getGlobalHistoryCacheKey([]));
        }
    }

    protected function getEntityHistoryCacheKey($entity, array $filters): string
    {
        $prefix = config('fee.cache.prefix', 'fee_history:');
        $filterHash = md5(serialize($filters));

        return $prefix.'entity:'.get_class($entity).':'.$entity->getKey().':'.$filterHash;
    }

    protected function getGlobalHistoryCacheKey(array $filters): string
    {
        $prefix = config('fee.cache.prefix', 'fee_history:');
        $filterHash = md5(serialize($filters));

        return $prefix.'global:'.$filterHash;
    }
}
