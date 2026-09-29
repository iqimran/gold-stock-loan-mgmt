<?php

namespace App\Enums;

/**
 * Loan statuses recommended by docs/01-requirements.md. Transitions are not defined here; they are
 * centralized in the loan domain service (docs/tasks/006-loan-backend.md).
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
}
