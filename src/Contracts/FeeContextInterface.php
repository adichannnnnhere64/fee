<?php

namespace Repay\Fee\Contracts;

interface FeeContextInterface
{
    public function getKey();

    public function getMorphClass();

    // The entity that fee rules are attached to (usually seller/provider)
    public function getFeeEntity();

    // The buyer/customer who pays certain fees
    public function getBuyer();

    // The seller/provider who receives certain fees
    public function getSeller();

    // The total amount for fee calculation
    public function getAmountForFeeCalculation(): float;

    // Item type (product, service, subscription, etc.)
    public function getItemType(): string;

    public function getCurrency(): string;

    public function getDescription(): string;
}
