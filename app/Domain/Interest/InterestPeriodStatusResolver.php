<?php

namespace App\Domain\Interest;

use App\Enums\InterestPeriodStatus;
use App\Support\Money;

/**
 * A period's status, derived only from its facts and the business date (deterministic):
 *
 *   waived          waiver recorded
 *   paid            paid interest ≥ expected interest
 *   overdue         due date before the missed cutoff, not fully paid (partly paid included: the missed rule)
 *   partially_paid  something paid, not yet overdue
 *   due             due today or earlier but still within the grace period, nothing paid
 *   upcoming        due date after today, nothing paid
 *
 * The missed cutoff is today minus the grace period (Settings; business-decided to apply to all open
 * loans). With no grace period it is today, so "due" means due today.
 */
final class InterestPeriodStatusResolver
{
    /**
     * @param  string|null  $missedCutoff  due before this date = missed; defaults to today (no grace period)
     */
    public static function resolve(string $expected, string $paid, bool $waived, string $dueDate, string $today, ?string $missedCutoff = null): InterestPeriodStatus
    {
        return match (true) {
            $waived => InterestPeriodStatus::Waived,
            Money::cmp($paid, $expected) >= 0 => InterestPeriodStatus::Paid,
            $dueDate < ($missedCutoff ?? $today) => InterestPeriodStatus::Overdue,
            Money::isPositive($paid) => InterestPeriodStatus::PartiallyPaid,
            $dueDate <= $today => InterestPeriodStatus::Due,
            default => InterestPeriodStatus::Upcoming,
        };
    }
}
