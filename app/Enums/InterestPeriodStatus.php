<?php

namespace App\Enums;

/**
 * Interest period states (docs/01). Derived deterministically from the facts by
 * App\Domain\Interest\InterestPeriodStatusResolver; never set by hand.
 */
enum InterestPeriodStatus: string
{
    case Upcoming = 'upcoming';
    case Due = 'due';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Waived = 'waived';
}
