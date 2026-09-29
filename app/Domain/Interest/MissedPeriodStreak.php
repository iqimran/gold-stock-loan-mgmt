<?php

namespace App\Domain\Interest;

use App\Enums\InterestPeriodStatus;

/**
 * The business-decided consecutive-missed rule, in one place (docs/08 Interest 3–5):
 *
 * A period is missed when its due date is before the missed cutoff (today minus the grace period) and it
 * is neither fully paid nor waived — its status resolves to "overdue"
 * (App\Domain\Interest\InterestPeriodStatusResolver); a partial payment does not settle it. The streak is the run of missed periods counted back from the latest past-due period,
 * stopping at the first settled (paid or waived) one. Paying or waiving a period resets the count.
 *
 * Pure: works on period rows whose status was resolved for the same business date.
 */
final class MissedPeriodStreak
{
    /**
     * The missed periods of the current streak, oldest first.
     *
     * @template T of array{due_date: string, status: string}
     *
     * @param  list<T>  $periods  one loan's periods, oldest due first, statuses resolved for the same date
     * @param  string  $missedCutoff  today minus the grace period (today when there is none)
     * @return list<T>
     */
    public static function of(array $periods, string $missedCutoff): array
    {
        $streak = [];

        foreach (array_reverse($periods) as $period) {
            if ($period['due_date'] >= $missedCutoff) {
                continue; // not missable yet (not due, or within the grace period): neither missed nor settled
            }

            if ($period['status'] !== InterestPeriodStatus::Overdue->value) {
                break;
            }

            $streak[] = $period;
        }

        return array_reverse($streak);
    }
}
