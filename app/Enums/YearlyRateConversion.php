<?php

namespace App\Enums;

/**
 * How a yearly (per annum) rate becomes a monthly period's rate.
 */
enum YearlyRateConversion: string
{
    /** Rate ÷ 12: every month costs the same. */
    case Twelfths = 'twelfths';

    /** Rate × days in the period ÷ 365. */
    case ActualDays = 'actual_days';
}
