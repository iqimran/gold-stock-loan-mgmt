<?php

namespace App\Enums;

/**
 * Loan statuses recommended by docs/01-requirements.md. Which transitions are allowed is defined
 * in one place: App\Domain\Loan\LoanStatusTransitions.
 */
enum LoanStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Overdue = 'overdue';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Loans that are running: they accrue interest and count as a customer's active loans.
     *
     * @return list<string>
     */
    public static function open(): array
    {
        return [self::Active->value, self::Overdue->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::open(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
