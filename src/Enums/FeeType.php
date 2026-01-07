<?php

namespace Repay\Fee\Enums;

enum FeeType: string
{
    case MARKUP = 'markup';
    case COMMISSION = 'commission';
    case CONVENIENCE = 'convenience';

    public function label(): string
    {
        return match ($this) {
            self::MARKUP => 'Markup',
            self::COMMISSION => 'Commission',
            self::CONVENIENCE => 'Convenience Fee',
        };
    }
}
