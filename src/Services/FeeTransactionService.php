<?php

namespace Repay\Fee\Services;

use Repay\Fee\Enums\FeeTransactionStatus;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;

class FeeTransactionService
{
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
                'calculation_type' => $feeRule->calculation_type,
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
}
