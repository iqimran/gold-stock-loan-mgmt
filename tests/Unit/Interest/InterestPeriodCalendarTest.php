<?php

namespace Tests\Unit\Interest;

use App\Domain\Interest\InterestPeriodBounds;
use App\Domain\Interest\InterestPeriodCalendar;
use App\Enums\InterestDueTiming;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterestPeriodCalendarTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string, 2: string}> [start, end, due]
     */
    private function periods(string $loanStart, int $count, InterestDueTiming $due = InterestDueTiming::PeriodEnd): array
    {
        $calendar = new InterestPeriodCalendar($due);

        return array_map(fn (int $index) => [
            ($period = $calendar->period($loanStart, $index))->start, $period->end, $period->due,
        ], range(0, $count - 1));
    }

    // ── period boundaries ───────────────────────────────────────────────────────────────────

    public function test_periods_are_monthly_anniversaries_ending_the_day_before_the_next(): void
    {
        $this->assertSame([
            ['2026-01-15', '2026-02-14', '2026-02-14'],
            ['2026-02-15', '2026-03-14', '2026-03-14'],
            ['2026-03-15', '2026-04-14', '2026-04-14'],
        ], $this->periods('2026-01-15', 3));
    }

    public function test_a_first_of_month_start_gives_calendar_months(): void
    {
        $this->assertSame([
            ['2026-02-01', '2026-02-28', '2026-02-28'],
            ['2026-03-01', '2026-03-31', '2026-03-31'],
        ], $this->periods('2026-02-01', 2));
    }

    public function test_day_31_clamps_to_short_months_and_returns_afterwards(): void
    {
        $this->assertSame([
            ['2026-01-31', '2026-02-27', '2026-02-27'],
            ['2026-02-28', '2026-03-30', '2026-03-30'],
            ['2026-03-31', '2026-04-29', '2026-04-29'],
            ['2026-04-30', '2026-05-30', '2026-05-30'],
            ['2026-05-31', '2026-06-29', '2026-06-29'],
        ], $this->periods('2026-01-31', 5));
    }

    public function test_leap_years(): void
    {
        // 31 Jan in a leap year → 29 Feb.
        $this->assertSame(['2024-02-29', '2024-03-30', '2024-03-30'], $this->periods('2024-01-31', 2)[1]);

        // A loan started on 29 Feb: 28 Feb in common years, back to 29 in the following months.
        $calendar = new InterestPeriodCalendar;
        $this->assertSame('2025-02-28', $calendar->period('2024-02-29', 12)->start);
        $this->assertSame('2025-03-29', $calendar->period('2024-02-29', 13)->start);
        $this->assertSame('2028-02-29', $calendar->period('2024-02-29', 48)->start);
    }

    public function test_year_rollover(): void
    {
        $this->assertSame([
            ['2026-12-10', '2027-01-09', '2027-01-09'],
            ['2027-01-10', '2027-02-09', '2027-02-09'],
        ], $this->periods('2026-12-10', 2));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function awkwardStarts(): array
    {
        return array_combine(
            ['1st', '15th', '28th', '29th', '30th', '31st', '29 Feb (leap)', 'year end'],
            array_map(fn ($date) => [$date], ['2026-01-01', '2026-01-15', '2026-01-28', '2026-01-29', '2026-01-30', '2026-01-31', '2024-02-29', '2026-12-31']),
        );
    }

    #[DataProvider('awkwardStarts')]
    public function test_periods_are_contiguous_without_gaps_or_overlaps_for_ten_years(string $loanStart): void
    {
        $calendar = new InterestPeriodCalendar;
        $previous = null;

        for ($index = 0; $index < 120; $index++) {
            $period = $calendar->period($loanStart, $index);

            $this->assertGreaterThanOrEqual(28, $period->days(), "Period {$index} too short");
            $this->assertLessThanOrEqual(31, $period->days(), "Period {$index} too long");
            $this->assertLessThan($period->end, $period->start);

            if ($previous) {
                $this->assertSame(
                    CarbonImmutable::parse($previous->end, 'UTC')->addDay()->toDateString(),
                    $period->start,
                    "Gap or overlap before period {$index}",
                );
            }

            $previous = $period;
        }

        // Ten years of monthly periods end exactly the day before the tenth anniversary.
        $this->assertSame(CarbonImmutable::parse($loanStart, 'UTC')->addYearsNoOverflow(10)->subDay()->toDateString(), $previous->end);
    }

    public function test_period_length_in_days(): void
    {
        $this->assertSame(28, (new InterestPeriodBounds(0, '2026-02-01', '2026-02-28', '2026-02-28'))->days());
        $this->assertSame(29, (new InterestPeriodBounds(0, '2024-02-01', '2024-02-29', '2024-02-29'))->days());
        $this->assertSame(31, (new InterestPeriodBounds(0, '2026-12-15', '2027-01-14', '2027-01-14'))->days());
    }

    public function test_daylight_saving_changes_do_not_shift_dates(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/New_York'); // DST starts 8 Mar 2026, ends 1 Nov 2026

        try {
            $this->assertSame([
                ['2026-03-08', '2026-04-07', '2026-04-07'],
                ['2026-04-08', '2026-05-07', '2026-05-07'],
            ], $this->periods('2026-03-08', 2));
            $this->assertSame(31, (new InterestPeriodCalendar)->period('2026-10-15', 0)->days()); // spans 1 Nov
        } finally {
            date_default_timezone_set($original);
        }
    }

    // ── due dates ───────────────────────────────────────────────────────────────────────────

    public function test_interest_is_due_on_the_last_day_by_default(): void
    {
        $period = (new InterestPeriodCalendar)->period('2026-01-15', 0);

        $this->assertSame($period->end, $period->due);
    }

    public function test_interest_can_be_due_on_the_first_day(): void
    {
        $this->assertSame([
            ['2026-01-31', '2026-02-27', '2026-01-31'],
            ['2026-02-28', '2026-03-30', '2026-02-28'],
        ], $this->periods('2026-01-31', 2, InterestDueTiming::PeriodStart));
    }

    // ── which periods have started ──────────────────────────────────────────────────────────

    public function test_periods_started_by_a_date(): void
    {
        $calendar = new InterestPeriodCalendar;
        $starts = fn (string $date) => array_map(fn (InterestPeriodBounds $p) => $p->start, $calendar->startedBy('2026-01-15', $date));

        $this->assertSame([], $starts('2026-01-14'));                                   // before the loan starts
        $this->assertSame(['2026-01-15'], $starts('2026-01-15'));                       // first day
        $this->assertSame(['2026-01-15'], $starts('2026-02-14'));                       // last day of period 1
        $this->assertSame(['2026-01-15', '2026-02-15'], $starts('2026-02-15'));         // next period starts
        $this->assertCount(12, $calendar->startedBy('2026-01-15', '2026-12-31'));
    }

    // ── input validation ────────────────────────────────────────────────────────────────────

    public function test_invalid_input_is_rejected(): void
    {
        $calendar = new InterestPeriodCalendar;

        foreach (['2026-02-30', '15/01/2026', '2026-1-5', ''] as $date) {
            try {
                $calendar->period($date, 0);
                $this->fail("Accepted start date [{$date}].");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $calendar->period('2026-01-15', -1);
    }
}
