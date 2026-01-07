<?php

namespace Repay\Fee\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Repay\Fee\Models\FeeRule getActiveFeeFor($entity, string $itemType)
 * @method static \Repay\Fee\Models\FeeRule setFeeForEntity(array $data, $entity)
 * @method static \Repay\Fee\Models\FeeRule createGlobalFee(array $data)
 * @method static array calculateFor($entity, float $amount, string $itemType)
 * @method static \Illuminate\Support\Collection getAllActiveFeesFor($entity)
 * @method static \Illuminate\Support\Collection getGlobalFees()
 * @method static void clearCacheForEntity($entity)
 * @method static array getHistoryForEntity($entity, array $filters = [])
 * @method static array getGlobalHistory(array $filters = [])
 * @method static void logFeeChange($feeRule, array $oldData, string $reason)
 * @method static array getLatestUpcomingFees($entity = null)
 * @method static \Repay\Fee\Models\FeeRule|null getUpcomingFee(string $itemType, $entity = null)
 * @method static void clearUpcomingCache($entity = null)
 */
class Fee extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fee';
    }
}
