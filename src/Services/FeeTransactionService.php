<?php

namespace Repay\Fee\Services;

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
}
