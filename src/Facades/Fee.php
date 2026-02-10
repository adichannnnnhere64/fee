<?php

namespace Repay\Fee\Facades;

use Illuminate\Support\Facades\Facade;
use Repay\Fee\DTO\CreateFee;
use Illuminate\Database\Eloquent\Builder;

/**
 * @method static \Repay\Fee\Models\FeeRule getActiveFeeFor($entity, string $itemType)
 * @method static \Repay\Fee\Models\FeeRule setFeeForEntity(array|CreateFee $data, $entity)
 * @method static \Repay\Fee\Models\FeeRule createGlobalFee(array|CreateFee $data)
 * @method static array calculateFor($entity, float $amount, string $itemType)
 * @method static \Illuminate\Support\Collection getAllActiveFeesFor($entity)
 * @method static \Illuminate\Support\Collection getGlobalFees()
 * @method static void clearCacheForEntity($entity)
 * @method static array getHistoryForEntity($entity, array $filters = [])
 * @method static array getGlobalHistory(array $filters = [])
 * @method static Builder getQueryGlobalHistory(array $filters = [])
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
 *
 *
 *  * @method static \Illuminate\Pagination\LengthAwarePaginator getAllFeeTransactions(array $filters = [])
 * @method static \Illuminate\Database\Eloquent\Builder getQueryAllFeeTransactions(array $filters = [])

 * @method static array getFeeTransactionStats(array $filters = [])
 * @method static \Illuminate\Support\Collection getFeeTransactionsByPeriod(string $period = 'day', array $filters = [])
 * @method static \Illuminate\Support\Collection getFeeTransactionsByFeeType(array $filters = [])
 * @method static \Illuminate\Pagination\LengthAwarePaginator searchFeeTransactions(string $searchTerm, array $filters = [])
 */
class Fee extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fee';
    }
}
