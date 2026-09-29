<?php

namespace App\Enums;

/**
 * A collateral item is held from intake until it is released (returned to the customer).
 * Released is final: the item stays in history and is never held again.
 */
enum CollateralStatus: string
{
    case Held = 'held';
    case Released = 'released';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
