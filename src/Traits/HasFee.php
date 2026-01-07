<?php

namespace Repay\Fee\Traits;

use Repay\Fee\Contracts\FeeableInterface;
use Repay\Fee\Models\FeeRule;
use Repay\Fee\Models\FeeTransaction;

trait HasFee
{
    /**
     * Get the fee transaction for this model
     */
    public function feeTransaction()
    {
        return $this->morphOne(FeeTransaction::class, 'feeable');
    }

    /**
     * Get the applicable fee rule for this model
     */
    public function getFeeRuleAttribute(): ?FeeRule
    {
        // Ensure the model implements FeeableInterface
        if (! $this instanceof FeeableInterface) {
            return null;
        }

        $feeEntity = $this->getFeeEntity();
        $itemType = $this->getFeeItemType();

        return app('fee.service')->getActiveFeeFor($feeEntity, $itemType);
    }

    /**
     * Get the calculated fee amount
     */
    public function getFeeAttribute(): float
    {
        if (! $this instanceof FeeableInterface) {
            return 0.0;
        }

        if ($this->feeTransaction) {
            return (float) $this->feeTransaction->fee_amount;
        }

        $feeRule = $this->fee_rule;
        if ($feeRule && $feeRule->effective_from < now()) {
            return (float) $feeRule->calculate($this->getFeeBaseAmount());
        }

        return 0.0;
    }

    /**
     * Get total amount with fee
     */
    public function getTotalWithFeeAttribute(): float
    {
        if (! $this instanceof FeeableInterface) {
            return 0.0;
        }

        return $this->getFeeBaseAmount() + $this->fee;
    }

    /**
     * Check if fee has been processed
     */
    public function getHasFeeProcessedAttribute(): bool
    {
        return $this->feeTransaction !== null;
    }

    /**
     * Process fee for this model
     */
    public function processFee(): array
    {
        if (! $this instanceof FeeableInterface) {
            return ['error' => 'Model does not implement FeeableInterface'];
        }

        return \Repay\Fee\Facades\Fee::processFeeForModel($this);
    }
}
