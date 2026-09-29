<?php

namespace App\Enums;

/**
 * How interest_rate (a percentage) is quoted. Converting it to the period's rate is the interest
 * engine's job (docs/tasks/007-interest-engine.md).
 */
enum InterestRateType: string
{
    /** Percent per month. */
    case Monthly = 'monthly';

    /** Percent per annum. */
    case Yearly = 'yearly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
