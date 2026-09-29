<?php

namespace App\Enums;

/**
 * Entries in a loan's history (loan_events).
 */
enum LoanEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Activated = 'activated';
    case MarkedOverdue = 'marked_overdue';
    case OverdueCleared = 'overdue_cleared';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    // Collateral held against the loan (payload carries the collateral number).
    case CollateralAdded = 'collateral_added';
    case CollateralUpdated = 'collateral_updated';
    case CollateralReleased = 'collateral_released';

    // Payments (payload carries the receipt number and the allocation).
    case PaymentPosted = 'payment_posted';
}
