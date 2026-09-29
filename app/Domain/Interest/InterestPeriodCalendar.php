<?php

namespace App\Domain\Interest;

use App\Enums\InterestDueTiming;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Where each interest period of a loan starts, ends and falls due (business-decided layout).
 *
 * Periods are monthly anniversaries of the loan's start date: period n starts n months after the
 * start date. Each anniversary is computed from the ORIGINAL start date (never from the previous
 * period), so a start day of 29–31 clamps to the end of short months and comes back afterwards:
 * 31 Jan → 28/29 Feb → 31 Mar. A period ends the day before the next one starts.
 *
 * Pure date arithmetic on Y-m-d strings (computed in UTC, so no time zone or DST can shift a date).
 */
final class InterestPeriodCalendar
{
    public function __construct(private readonly InterestDueTiming $dueTiming = InterestDueTiming::PeriodEnd) {}

    /**
     * @param  int  $index  0 for the first period
     */
    public function period(string $loanStart, int $index): InterestPeriodBounds
    {
        if ($index < 0) {
            throw new InvalidArgumentException('Period index must be zero or positive.');
        }

        $start = $this->anniversary($loanStart, $index);
        $end = $this->anniversary($loanStart, $index + 1)->subDay();

        return new InterestPeriodBounds(
            index: $index,
            start: $start->toDateString(),
            end: $end->toDateString(),
            due: ($this->dueTiming === InterestDueTiming::PeriodEnd ? $end : $start)->toDateString(),
        );
    }

    /**
     * Every period that has started on or before $date (empty when the loan starts later).
     *
     * @return list<InterestPeriodBounds>
     */
    public function startedBy(string $loanStart, string $date): array
    {
        $periods = [];

        for ($index = 0; ($period = $this->period($loanStart, $index))->start <= $date; $index++) {
            $periods[] = $period;
        }

        return $periods;
    }

    private function anniversary(string $loanStart, int $months): CarbonImmutable
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $loanStart, 'UTC');

        if ($start === false || $start->toDateString() !== $loanStart) {
            throw new InvalidArgumentException("Invalid loan start date [{$loanStart}].");
        }

        return $start->addMonthsNoOverflow($months);
    }
}
