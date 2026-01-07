<?php

namespace Repay\Fee\Contracts;

use Repay\Fee\Models\FeeRule;

interface UpcomingFeeInterface
{
    public function getLatestUpcomingFees($entity = null): array;
    public function getUpcomingFeeForItemType(string $itemType, $entity = null): ?FeeRule;
}
