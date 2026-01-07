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
 * @method static \Repay\Fee\Models\FeeTransaction recordFee(...$args)
 * @method static \Repay\Fee\Models\FeeTransaction reverseFee(...$args)
 * @method static \Illuminate\Pagination\LengthAwarePaginator getFeesForBearer($bearer, array $filters = [])
 * @method static array getTotalFeesForBearer($bearer, array $filters = [])
 *
 * // Model-based methods
 * @method static array processFeeForModel(\Repay\Fee\Contracts\FeeableInterface $model, ?string $transactionId = null)
 * @method static array calculateFeeForModel(\Repay\Fee\Contracts\FeeableInterface $model)
 * @method static bool hasFeeProcessed($model)
 * @method static \Repay\Fee\Models\FeeTransaction|null getTransactionFor($model)
 * @method static \Illuminate\Pagination\LengthAwarePaginator getTransactionsForModelType(string $modelClass, array $filters = [])
 *
 * Analytics Methods
 * @method static array getMonthlyRevenueAnalytics(int $year, int $month, array $filters = [])
 * @method static array getRevenueByDateRange(array $filters = [])
 * @method static array getRevenueByFeeType(array $filters = [])
 * @method static array getEntityRevenue(array $filters = [])
 * @method static array getTopRevenueGenerators(array $filters = [])
 * @method static array getDailyBreakdown(array $filters = [])
 * @method static array getHourlyBreakdown(array $filters = [])
 * @method static array getComparativeAnalysis(array $filters1 = [], array $filters2 = [])
 * @method static array getCustomReport(array $filters = [], array $metrics = [])
 */
class Fee extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fee';
    }
}
