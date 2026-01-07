<?php

namespace Repay\Fee\Contracts;

use Repay\Fee\Models\FeeRule;

interface FeeHistoryInterface
{
    public function getForEntity($entity, array $filters = []): array;

    public function getGlobal(array $filters = []): array;

    public function logChange(FeeRule $feeRule, array $oldData, string $reason): void;
}
