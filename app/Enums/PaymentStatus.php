<?php

namespace App\Enums;

/**
 * A payment is posted once; a reversal (docs/tasks/011) marks it reversed and posts compensating
 * entries. Its financial amount is never edited (docs/08 Payments 4).
 */
enum PaymentStatus: string
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
