<?php

namespace App\Enums;

/**
 * Which day of a period its interest is due.
 */
enum InterestDueTiming: string
{
    /** Last day of the period (in arrears). */
    case PeriodEnd = 'period_end';

    /** First day of the period (in advance). */
    case PeriodStart = 'period_start';
}
