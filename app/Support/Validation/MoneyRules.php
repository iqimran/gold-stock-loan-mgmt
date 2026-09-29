<?php

namespace App\Support\Validation;

/**
 * Validation rules for money and rates, bounded by the database columns
 * (money DECIMAL(18,2), interest rate DECIMAL(8,4)).
 */
final class MoneyRules
{
    public const MAX = '9999999999999999.99';

    public const MAX_RATE = '9999.9999';

    /**
     * @return list<string>
     */
    public static function amount(bool $required = true, string $min = '0'): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'decimal:0,2', "min:{$min}", 'max:'.self::MAX];
    }

    /**
     * @return list<string>
     */
    public static function positive(): array
    {
        return self::amount(true, '0.01');
    }

    /**
     * A percentage rate with up to 4 decimals.
     *
     * @return list<string>
     */
    public static function rate(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:'.self::MAX_RATE];
    }
}
