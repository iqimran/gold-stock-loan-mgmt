<?php

namespace App\Enums;

/**
 * The amount a period's interest is calculated on.
 */
enum InterestBase: string
{
    /** Outstanding principal at the start of the period (reducing balance). */
    case Outstanding = 'outstanding';

    /** The loan's original principal (flat). */
    case Principal = 'principal';
}
