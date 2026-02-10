<?php

namespace Repay\Fee\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Contracts\FeeContextInterface;
use Repay\Fee\Enums\FeeTransactionStatus;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;

class FeeTransactionService
{
    public function recordFeeFromContext(
        FeeRule $feeRule,
        FeeContextInterface $context,
        ?string $transactionId = null,
        ?string $referenceNumber = null,
        array $metadata = []
    ): FeeTransaction {
        $feeBearer = $this->determineFeeBearerFromContext($feeRule, $context);

        return $this->recordFee(
            feeRule: $feeRule,
            feeBearer: $feeBearer,
            feeable: $context,
            transactionAmount: $context->getAmountForFeeCalculation(),
            feeAmount: $feeRule->calculate($context->getAmountForFeeCalculation()),
            transactionId: $transactionId,
            referenceNumber: $referenceNumber,
            metadata: array_merge($metadata, [
                'context_type' => $context->getMorphClass(),
                'item_type' => $context->getItemType(),
                'buyer_id' => $context->getBuyer()->getKey(),
                'seller_id' => $context->getSeller()->getKey(),
            ])
        );
    }

    public function determineFeeBearerFromContext(
        FeeRule $feeRule,
        FeeContextInterface $context
    ) {

        return match ($feeRule->fee_type) {
            'commission' => $context->getSeller(),
            'markup', 'convenience' => $context->getBuyer(),
            default => throw new \InvalidArgumentException("Unknown fee type: {$feeRule->fee_type}")
        };
    }

    public function recordFee(
        FeeRule $feeRule,
        $feeBearer,
        $feeable,
        float $transactionAmount,
        float $feeAmount,
        ?string $transactionId = null,
        ?string $referenceNumber = null,
        array $metadata = []
    ): FeeTransaction {
        $transaction = FeeTransaction::create([
            'transaction_id' => $transactionId ?? $this->generateTransactionId(),
            'fee_rule_id' => $feeRule->id,
            'fee_bearer_type' => get_class($feeBearer),
            'fee_bearer_id' => $feeBearer->getKey(),
            'feeable_type' => get_class($feeable),
            'feeable_id' => $feeable->getKey(),
            'transaction_amount' => $transactionAmount,
            'fee_amount' => $feeAmount,
            'fee_type' => $feeRule->fee_type,
            'status' => FeeTransactionStatus::APPLIED->value,
            'reference_number' => $referenceNumber,
            'metadata' => array_merge($metadata, [
                'fee_rule_snapshot' => $feeRule->toArray(),
                'calculation_type' => $feeRule->calculation_type->label(),
                'rate_used' => $feeRule->value,
                'is_global' => $feeRule->is_global,
            ]),
        ]);

        return $transaction;
    }

    public function reverseFee(FeeTransaction $transaction, string $reason): FeeTransaction
    {
        $transaction->update([
            'status' => FeeTransactionStatus::REVERSED->value,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]),
        ]);

        return $transaction;
    }

    public function getFeesForBearer($bearer, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = FeeTransaction::forFeeBearer($bearer)
            /* ->with(['feeRule', 'feeable']) */
            ->with(['feeRule'])
            ->orderBy('applied_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('applied_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('applied_at', '<=', $filters['end_date']);
        }

        if (isset($filters['fee_type'])) {
            $query->where('fee_type', $filters['fee_type']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function getTotalFeesForBearer($bearer, array $filters = []): array
    {
        $query = FeeTransaction::forFeeBearer($bearer)
            ->where('status', FeeTransactionStatus::APPLIED->value);

        if (isset($filters['start_date'])) {
            $query->whereDate('applied_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('applied_at', '<=', $filters['end_date']);
        }

        if (isset($filters['fee_type'])) {
            $query->where('fee_type', $filters['fee_type']);
        }

        return [
            'total_transactions' => $query->count(),
            'total_fee_amount' => $query->sum('fee_amount'),
            'total_transaction_amount' => $query->sum('transaction_amount'),
        ];
    }

    protected function generateTransactionId(): string
    {
        return 'FEE-'.now()->format('YmdHis').'-'.str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function processFeeForModel(FeeableInterface $model, ?string $transactionId = null): array
    {
        // Check if already has fee transaction
        if ($model->feeTransaction) {
            return [
                'already_processed' => true,
                'transaction' => $model->feeTransaction,
            ];
        }

        $feeEntity = $model->getFeeEntity();
        $itemType = $model->getFeeItemType();
        $amount = $model->getFeeBaseAmount();

        // Get the fee rule (you'll need to inject or get the FeeService)
        $feeService = app('fee.service');
        $feeRule = $feeService->getActiveFeeFor($feeEntity, $itemType);

        if (! $feeRule) {
            return [
                'has_fee' => false,
                'amount' => $amount,
                'reason' => 'No fee rule found',
            ];
        }

        // Determine fee bearer
        $feeBearer = $this->determineFeeBearerForModel($feeRule, $model);

        // Record transaction
        $transaction = $this->recordFee(
            feeRule: $feeRule,
            feeBearer: $feeBearer,
            feeable: $model,
            transactionAmount: $amount,
            feeAmount: $feeRule->calculate($amount),
            transactionId: $transactionId,
            metadata: [
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'processed_via' => 'processFeeForModel',
            ]
        );

        return [
            'has_fee' => true,
            'fee_amount' => $transaction->fee_amount,
            'total_with_fee' => $amount + $transaction->fee_amount,
            'transaction' => $transaction,
            'fee_rule' => $feeRule,
        ];
    }

    /**
     * Determine fee bearer for a FeeableInterface model
     */
    protected function determineFeeBearerForModel(FeeRule $feeRule, FeeableInterface $model)
    {
        // Try to get buyer/seller methods if they exist
        if (method_exists($model, 'getBuyer') && method_exists($model, 'getSeller')) {
            return match ($feeRule->fee_type) {
                'commission' => $model->getSeller(),
                'markup', 'convenience' => $model->getBuyer(),
                default => throw new \InvalidArgumentException("Unknown fee type: {$feeRule->fee_type}")
            };
        }

        // Fallback: if no buyer/seller methods, use fee entity for commission, null for others
        return match ($feeRule->fee_type) {
            'commission' => $model->getFeeEntity(),
            'markup', 'convenience' => null, // Need buyer for these
            default => throw new \InvalidArgumentException("Unknown fee type: {$feeRule->fee_type}")
        };
    }

    /**
     * Calculate fee without recording transaction
     */
    public function calculateFeeForModel(FeeableInterface $model): array
    {
        $feeEntity = $model->getFeeEntity();
        $itemType = $model->getFeeItemType();
        $amount = $model->getFeeBaseAmount();

        $feeService = app('fee.service');
        $feeRule = $feeService->getActiveFeeFor($feeEntity, $itemType);

        if (! $feeRule) {
            return ['has_fee' => false, 'fee_amount' => 0];
        }

        $feeAmount = $feeRule->calculate($amount);

        return [
            'has_fee' => true,
            'fee_amount' => $feeAmount,
            'total_with_fee' => $amount + $feeAmount,
            'fee_rule' => $feeRule,
        ];
    }

    /**
     * Check if model has fee processed
     */
    public function hasFeeProcessed($model): bool
    {
        return FeeTransaction::where('feeable_type', get_class($model))
            ->where('feeable_id', $model->id)
            ->exists();
    }

    /**
     * Get fee transaction for any model
     */
    public function getTransactionFor($model): ?FeeTransaction
    {
        return FeeTransaction::where('feeable_type', get_class($model))
            ->where('feeable_id', $model->id)
            ->first();
    }

    /**
     * Get all fee transactions for a model type
     */
    public function getTransactionsForModelType(string $modelClass, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = FeeTransaction::where('feeable_type', $modelClass)
            ->with(['feeRule', 'feeBearer']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['start_date'])) {
            $query->whereDate('applied_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->whereDate('applied_at', '<=', $filters['end_date']);
        }

        if (isset($filters['fee_type'])) {
            $query->where('fee_type', $filters['fee_type']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

      public function getAllFeeTransactions(array $filters = []): LengthAwarePaginator

    {
        $query = FeeTransaction::query()
            ->with(['feeRule']) // Keep it simple, remove feeBearer/feeable for now
            ->orderBy('applied_at', 'desc');

        // Apply filters
        $query = $this->applyTransactionFilters($query, $filters);


        return $query->paginate($filters['per_page'] ?? 15);
    }

      public function getQueryAllFeeTransactions(array $filters = []): Builder

    {
        $query = FeeTransaction::query()
            ->with(['feeRule'])
            ->orderBy('applied_at', 'desc');

        // Apply filters
        $query = $this->applyTransactionFilters($query, $filters);


        return $query;
    }

    /**
     * Get fee transactions summary statistics
     */
    public function getFeeTransactionStats(array $filters = []): array
    {
        $query = FeeTransaction::query();
        $query = $this->applyTransactionFilters($query, $filters);

        return [

            'total_transactions' => $query->count(),
            'total_fee_amount' => (float) $query->sum('fee_amount'),
            'total_transaction_amount' => (float) $query->sum('transaction_amount'),
            'avg_fee_amount' => (float) $query->avg('fee_amount'),
            'min_fee_amount' => (float) $query->min('fee_amount'),
            'max_fee_amount' => (float) $query->max('fee_amount'),
        ];
    }

    /**
     * Get fee transactions grouped by period (day, week, month, year)
     * Using PHP grouping instead of database grouping
     */
    public function getFeeTransactionsByPeriod(string $period = 'day', array $filters = []): Collection
    {
        $query = FeeTransaction::query();
        $query = $this->applyTransactionFilters($query, $filters);
        $transactions = $query->orderBy('applied_at')->get();

        $grouped = collect();


        foreach ($transactions as $transaction) {
            $date = Carbon::parse($transaction->applied_at);


            $periodKey = match ($period) {
                'hour' => $date->format('Y-m-d H:00:00'),

                'day' => $date->format('Y-m-d'),
                'week' => $date->format('Y-W'),
                'month' => $date->format('Y-m'),
                'year' => $date->format('Y'),
                default => $date->format('Y-m-d'),
            };

            if (!$grouped->has($periodKey)) {
                $grouped->put($periodKey, [
                    'period' => $periodKey,
                    'transaction_count' => 0,
                    'total_fee_amount' => 0,
                    'total_transaction_amount' => 0,
                    'fee_amounts' => [],
                ]);
            }

            $group = $grouped->get($periodKey);
            $group['transaction_count']++;
            $group['total_fee_amount'] += (float) $transaction->fee_amount;
            $group['total_transaction_amount'] += (float) $transaction->transaction_amount;
            $group['fee_amounts'][] = (float) $transaction->fee_amount;

            $grouped->put($periodKey, $group);
        }

        // Calculate averages
        return $grouped->map(function ($group) {

            $group['avg_fee_amount'] = $group['transaction_count'] > 0
                ? $group['total_fee_amount'] / $group['transaction_count']
                : 0;
            $group['min_fee_amount'] = !empty($group['fee_amounts']) ? min($group['fee_amounts']) : 0;
            $group['max_fee_amount'] = !empty($group['fee_amounts']) ? max($group['fee_amounts']) : 0;
            unset($group['fee_amounts']);
            return $group;

        })->sortByDesc('period')->values();

    }

    /**
     * Get fee transactions by fee type
     */
    public function getFeeTransactionsByFeeType(array $filters = []): Collection
    {
        $query = FeeTransaction::query();

        $filtersWithoutFeeType = array_diff_key($filters, ['fee_type' => null]);
        $query = $this->applyTransactionFilters($query, $filtersWithoutFeeType);
        $transactions = $query->get();

        $grouped = $transactions->groupBy('fee_type')->map(function ($group, $feeType) {

            $feeAmounts = $group->pluck('fee_amount')->map(fn($amount) => (float) $amount);

            return [
                'fee_type' => $feeType,
                'transaction_count' => $group->count(),
                'total_fee_amount' => $group->sum('fee_amount'),
                'total_transaction_amount' => $group->sum('transaction_amount'),
                'avg_fee_amount' => $feeAmounts->avg(),
                'min_fee_amount' => $feeAmounts->min(),
                'max_fee_amount' => $feeAmounts->max(),
            ];

        });


        return $grouped->sortByDesc('total_fee_amount')->values();
    }

    public function searchFeeTransactions(string $searchTerm, array $filters = []): LengthAwarePaginator
    {
        $query = FeeTransaction::query()
            ->with(['feeRule'])
            ->where(function ($q) use ($searchTerm) {
                $q->where('transaction_id', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('reference_number', 'LIKE', "%{$searchTerm}%");
            });

        $query = $this->applyTransactionFilters($query, $filters);

        return $query->orderBy('applied_at', 'desc')
                    ->paginate($filters['per_page'] ?? 15);

    }

    /**

     * Helper method to apply filters to query
     */
    protected function applyTransactionFilters($query, array $filters)
    {
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['fee_type'])) {
            if (is_array($filters['fee_type'])) {
                $query->whereIn('fee_type', $filters['fee_type']);
            } else {
                $query->where('fee_type', $filters['fee_type']);
            }

        }


        if (isset($filters['start_date'])) {
            $query->whereDate('applied_at', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {

            $query->whereDate('applied_at', '<=', $filters['end_date']);
        }

        if (isset($filters['fee_bearer_type'])) {
            $query->where('fee_bearer_type', $filters['fee_bearer_type']);
        }

        if (isset($filters['fee_bearer_id'])) {

            $query->where('fee_bearer_id', $filters['fee_bearer_id']);
        }

        if (isset($filters['feeable_type'])) {
            $query->where('feeable_type', $filters['feeable_type']);

        }


        if (isset($filters['feeable_id'])) {
            $query->where('feeable_id', $filters['feeable_id']);

        }

        if (isset($filters['min_amount'])) {
            $query->where('fee_amount', '>=', $filters['min_amount']);
        }

        if (isset($filters['max_amount'])) {
            $query->where('fee_amount', '<=', $filters['max_amount']);
        }

        return $query;

    }

}
