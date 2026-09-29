<?php

namespace App\Enums;

/**
 * Customers are archived, never hard-deleted (docs/08 "Customer deletion").
 */
enum CustomerStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
