<?php

namespace Repay\Fee;

use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Contracts\FeeContextInterface;
use Repay\Fee\DTO\AnalyticsFilter;
use Repay\Fee\DTO\MonthlyAnalyticsFilter;
use Repay\Fee\Services\AnalyticsService;
use Repay\Fee\Services\FeeHistoryService;
use Repay\Fee\Services\FeeService;
use Repay\Fee\Services\FeeTransactionService;
use Repay\Fee\Services\UpcomingFeeService;

class Fee
{
    protected FeeService $service;

    protected FeeHistoryService $history;

    protected UpcomingFeeService $upcoming;

    protected FeeTransactionService $transactions;

    public function __construct(
        FeeService $service,
        FeeHistoryService $history,
        UpcomingFeeService $upcoming,
        FeeTransactionService $transactions,
        protected AnalyticsService $analytics,
    ) {
        $this->service = $service;
        $this->history = $history;
        $this->upcoming = $upcoming;
        $this->transactions = $transactions;
    }

    /**
     * Handle dynamic method calls.
     * This delegates to the appropriate service.
     */
    public function __call($method, $parameters)
    {
        // First, check if it's an explicit method on this class
        if (method_exists($this, $method)) {
            return $this->$method(...$parameters);
        }

        // Then delegate to services
        if (method_exists($this->service, $method)) {
            return $this->service->{$method}(...$parameters);
        }

        if (method_exists($this->history, $method)) {
            return $this->history->{$method}(...$parameters);
        }

        if (method_exists($this->upcoming, $method)) {
            return $this->upcoming->{$method}(...$parameters);
        }

        // Add to __call method:

        if (method_exists($this->transactions, $method)) {
            return $this->transactions->{$method}(...$parameters);
        }

        if (method_exists($this->analytics, $method)) {
            return $this->analytics->{$method}(...$parameters);
        }

        throw new \BadMethodCallException("Method {$method} does not exist.");
    }

    // Add explicit methods:
    public function recordFeeTransaction(...$args)
    {
        return $this->transactions->recordFee(...$args);
    }

    public function reverseFeeTransaction(...$args)
    {
        return $this->transactions->reverseFee(...$args);
    }

    // Explicit methods for history
    public function getHistoryForEntity($entity, array $filters = []): array
    {
        return $this->history->getForEntity($entity, $filters);
    }

    public function getGlobalHistory(array $filters = []): array
    {
        return $this->history->getGlobal($filters);
    }

    public function logFeeChange($feeRule, array $oldData, string $reason): void
    {
        $this->history->logChange($feeRule, $oldData, $reason);
    }

    // Explicit methods for upcoming fees
    public function getLatestUpcomingFees($entity = null): array
    {
        return $this->upcoming->getLatestUpcomingFees($entity);
    }

    public function getUpcomingFee(string $itemType, $entity = null): ?\Repay\Fee\Models\FeeRule
    {
        return $this->upcoming->getUpcomingFeeForItemType($itemType, $entity);
    }

    public function clearUpcomingCache($entity = null): void
    {
        $this->upcoming->clearUpcomingCache($entity);
    }

    // Helper for tests
    public function clearHistoryCacheForEntityTypeAndId(string $entityType, $entityId): void
    {
        $this->history->clearHistoryCacheForEntityTypeAndId($entityType, $entityId);
    }

    // Analytics Methods
    public function getMonthlyRevenueAnalytics(int $year, int $month, array $filters = [])
    {
        $filter = MonthlyAnalyticsFilter::createForMonth($year, $month, $filters);

        return $this->analytics->getMonthlyRevenueAnalytics($filter);
    }

    public function getRevenueByDateRange(array $filters = [])
    {
        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getRevenueByDateRange($filter);

    }

    public function getRevenueByFeeType(array $filters = [])
    {
        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getRevenueByFeeType($filter);
    }

    public function getEntityRevenue(array $filters = [])
    {
        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getEntityRevenue($filter);

    }

    public function getTopRevenueGenerators(array $filters = [])
    {
        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getTopRevenueGenerators($filter);
    }

    public function getDailyBreakdown(array $filters = [])
    {
        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getDailyBreakdown($filter);

    }

    public function getHourlyBreakdown(array $filters = [])
    {

        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getHourlyBreakdown($filter);
    }

    public function getComparativeAnalysis(array $filters1 = [], array $filters2 = [])
    {
        $filter1 = AnalyticsFilter::create($filters1);
        $filter2 = AnalyticsFilter::create($filters2);

        return $this->analytics->getComparativeAnalysis($filter1, $filter2);
    }

    public function getCustomReport(array $filters = [], array $metrics = [])
    {

        $filter = AnalyticsFilter::create($filters);

        return $this->analytics->getCustomReport($filter, $metrics);
    }

    public function processFeeContext(FeeContextInterface $context): array
    {
        $feeEntity = $context->getFeeEntity();
        $itemType = $context->getItemType();
        $amount = $context->getAmountForFeeCalculation();

        // Get active fee rule
        // dd($fe)
        $feeRule = $this->service->getActiveFeeFor($feeEntity, $itemType);

        if (! $feeRule) {
            return ['has_fee' => false];
        }

        // Calculate fee
        $feeAmount = $feeRule->calculate($amount);

        // Record transaction using context-aware method
        $transaction = $this->transactions->recordFeeFromContext(
            feeRule: $feeRule,
            context: $context,
            metadata: [
                'auto_processed' => true,
                'total_with_fee' => $amount + $feeAmount,
            ]
        );

        return [
            'has_fee' => true,
            'fee_amount' => $feeAmount,
            'total_amount' => $amount,
            'total_with_fee' => $amount + $feeAmount,
            'fee_rule' => $feeRule,
            'transaction' => $transaction,
            'fee_bearer' => [
                'type' => get_class($transaction->feeBearer),
                'id' => $transaction->fee_bearer_id,
            ],
        ];
    }

    public function processFeeForModel(FeeableInterface $model, ?string $transactionId = null): array
    {
        return $this->transactions->processFeeForModel($model, $transactionId);
    }

    /**
     * Calculate fee without recording
     */
    public function calculateFeeForModel(FeeableInterface $model): array
    {
        return $this->transactions->calculateFeeForModel($model);
    }

    /**
     * Check if model has fee processed
     */
    public function hasFeeProcessed($model): bool
    {
        return $this->transactions->hasFeeProcessed($model);
    }

    /**
     * Get fee transaction for any model
     */
    public function getTransactionFor($model): ?\Repay\Fee\Models\FeeTransaction
    {
        return $this->transactions->getTransactionFor($model);
    }

    /**
     * Get all fee transactions for a model type
     */
    public function getTransactionsForModelType(string $modelClass, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->transactions->getTransactionsForModelType($modelClass, $filters);
    }

    /**
     * Process fee using context (for backward compatibility)
     */
    public function processFeeForContext(FeeContextInterface $context): array
    {
        // If context also implements FeeableInterface, use the simpler method
        if ($context instanceof FeeableInterface) {
            return $this->processFeeForModel($context);
        }

        // Otherwise use the full context method
        return $this->processFeeContext($context);
    }
}
