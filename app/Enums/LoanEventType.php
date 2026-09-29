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
}
