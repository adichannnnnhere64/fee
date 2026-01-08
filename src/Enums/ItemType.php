<?php

namespace Repay\Fee\Enums;

enum ItemType: string
{
    case PRODUCT = 0;
    case SERVICE = 1;

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'product',
            self::SERVICE => 'service',
        };
    }
}
