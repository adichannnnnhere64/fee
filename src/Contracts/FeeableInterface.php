<?php

namespace Repay\Fee\Contracts;

interface FeeableInterface
{
    /**
     * Get the entity that fee rules are attached to
     */
    public function getFeeEntity();

    /**
     * Get the item type for fee calculation
     */
    public function getFeeItemType(): string;

    /**
     * Get the base amount for fee calculation
     */
    public function getFeeBaseAmount(): float;
}
