<?php

namespace Repay\Fee\Enums;

enum CalculationType: string
{
    case FLAT = 0;
    case PERCENTAGE = 1;

    public function label(): string
    {
        return match ($this) {
            self::FLAT => 'fixed',
            self::PERCENTAGE => 'percentage',
        };
    }
}
