<?php

namespace App\Enums;

/**
 * Length of one interest period. Only monthly periods for now; further units come with Settings.
 */
enum InterestPeriodUnit: string
{
    case Month = 'month';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
