<?php

namespace Repay\Fee\Enums;

enum FeeTransactionStatus: string
{
    case PENDING = 'pending';
    case APPLIED = 'applied';
    case REVERSED = 'reversed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::APPLIED => 'Applied',
            self::REVERSED => 'Reversed',
            self::FAILED => 'Failed',
        };
    }
}
