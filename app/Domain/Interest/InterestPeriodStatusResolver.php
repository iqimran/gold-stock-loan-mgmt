<?php

namespace App\Domain\Interest;

use App\Enums\InterestPeriodStatus;
use App\Support\Money;

/**
 * A period's status, derived only from its facts and the business date (deterministic):
 *
 *   waived          waiver recorded
 *   paid            paid interest ≥ expected interest
 *   overdue         due date before today, not fully paid (partly paid included: the missed-period rule)
 *   partially_paid  something paid, not yet overdue
 *   due             due today, nothing paid
 *   upcoming        due date after today, nothing paid
 */
final class InterestPeriodStatusResolver
{
    public static function resolve(string $expected, string $paid, bool $waived, string $dueDate, string $today): InterestPeriodStatus
    {
        return match (true) {
            $waived => InterestPeriodStatus::Waived,
            Money::cmp($paid, $expected) >= 0 => InterestPeriodStatus::Paid,
            $dueDate < $today => InterestPeriodStatus::Overdue,
            Money::isPositive($paid) => InterestPeriodStatus::PartiallyPaid,
            $dueDate === $today => InterestPeriodStatus::Due,
            default => InterestPeriodStatus::Upcoming,
        };
    }
}
