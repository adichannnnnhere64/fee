<?php

namespace Repay\Fee;

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
        FeeTransactionService $transactions
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
}
