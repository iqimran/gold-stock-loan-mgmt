<?php

namespace Tests\Unit\Interest;

use App\Domain\Interest\InterestCalculationService;
use App\Domain\Interest\InterestPeriodBounds;
use App\Domain\Interest\InterestPeriodCalendar;
use App\Domain\Interest\InterestSettings;
use App\Enums\InterestBase;
use App\Enums\InterestDueTiming;
use App\Enums\InterestRateType;
use App\Enums\YearlyRateConversion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InterestCalculationTest extends TestCase
{
    private function calculator(YearlyRateConversion $conversion = YearlyRateConversion::Twelfths): InterestCalculationService
    {
        return new InterestCalculationService(new InterestSettings(InterestBase::Outstanding, InterestDueTiming::PeriodEnd, $conversion));
    }

    private function period(string $start = '2026-01-15', int $index = 0): InterestPeriodBounds
    {
        return (new InterestPeriodCalendar)->period($start, $index);
    }

    // ── rate calculation ────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: InterestRateType, 3: string}>
     */
    public static function rates(): array
    {
        return [
            'monthly rate applies per month' => ['50000.00', '2.5000', InterestRateType::Monthly, '1250.00'],
            'yearly 24% is 2% a month' => ['50000.00', '24.0000', InterestRateType::Yearly, '1000.00'],
            'yearly rate with a repeating twelfth' => ['10000.00', '25.0000', InterestRateType::Yearly, '208.33'],
            'fractional rate' => ['123456.78', '1.7500', InterestRateType::Monthly, '2160.49'],
            'four-decimal rate' => ['100000.00', '0.0001', InterestRateType::Monthly, '0.10'],
            'zero rate' => ['50000.00', '0.0000', InterestRateType::Monthly, '0.00'],
            'zero base (fully repaid)' => ['0.00', '3.0000', InterestRateType::Monthly, '0.00'],
            'yearly zero rate' => ['50000.00', '0', InterestRateType::Yearly, '0.00'],
        ];
    }

    #[DataProvider('rates')]
    public function test_expected_interest(string $base, string $rate, InterestRateType $type, string $expected): void
    {
        $this->assertSame($expected, $this->calculator()->expectedInterest($base, $rate, $type, $this->period()));
    }

    public function test_period_rate(): void
    {
        $calculator = $this->calculator();

        $this->assertSame('2.500000000000', $calculator->periodRate('2.5', InterestRateType::Monthly, $this->period()));
        $this->assertSame('2.000000000000', $calculator->periodRate('24', InterestRateType::Yearly, $this->period()));
        $this->assertSame('2.083333333333', $calculator->periodRate('25', InterestRateType::Yearly, $this->period()));
    }

    public function test_yearly_rate_in_twelfths_ignores_month_length(): void
    {
        $calendar = new InterestPeriodCalendar;
        $february = $calendar->period('2026-02-01', 0); // 28 days
        $march = $calendar->period('2026-03-01', 0);    // 31 days

        $this->assertSame('100.00', $this->calculator()->expectedInterest('10000.00', '12', InterestRateType::Yearly, $february));
        $this->assertSame('100.00', $this->calculator()->expectedInterest('10000.00', '12', InterestRateType::Yearly, $march));
    }

    public function test_yearly_rate_by_actual_days_follows_period_length(): void
    {
        $calendar = new InterestPeriodCalendar;
        $calculator = $this->calculator(YearlyRateConversion::ActualDays);

        // 36.5% p.a. on 1,000 is exactly 1.00 per day.
        $this->assertSame('28.00', $calculator->expectedInterest('1000.00', '36.5', InterestRateType::Yearly, $calendar->period('2026-02-01', 0)));
        $this->assertSame('29.00', $calculator->expectedInterest('1000.00', '36.5', InterestRateType::Yearly, $calendar->period('2024-02-01', 0)));
        $this->assertSame('31.00', $calculator->expectedInterest('1000.00', '36.5', InterestRateType::Yearly, $calendar->period('2026-03-01', 0)));
        $this->assertSame('30.00', $calculator->expectedInterest('1000.00', '36.5', InterestRateType::Yearly, $calendar->period('2026-04-01', 0)));
        $this->assertSame('3.000000000000', $calculator->periodRate('36.5', InterestRateType::Yearly, $calendar->period('2026-04-01', 0)));

        // A monthly rate is never converted.
        $this->assertSame('20.00', $calculator->expectedInterest('1000.00', '2', InterestRateType::Monthly, $calendar->period('2026-02-01', 0)));
    }

    // ── rounding (one policy: App\Support\Money, half away from zero, 2 decimals) ─────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: InterestRateType, 3: string}>
     */
    public static function roundingBoundaries(): array
    {
        return [
            'exactly half a cent rounds up' => ['1.00', '0.5', InterestRateType::Monthly, '0.01'],
            'just below half a cent rounds down' => ['0.99', '0.5', InterestRateType::Monthly, '0.00'],
            '1.5 cents rounds up' => ['1.00', '1.5', InterestRateType::Monthly, '0.02'],
            '0.49 cents rounds down' => ['0.49', '1', InterestRateType::Monthly, '0.00'],
            'exact .xx5 after a twelfth' => ['10002.00', '25', InterestRateType::Yearly, '208.38'],
            'repeating decimal below .xx5' => ['10001.00', '25', InterestRateType::Yearly, '208.35'],
            'rounded once, not per step' => ['333.33', '0.0003', InterestRateType::Yearly, '0.00'],
            'the float trap 0.1 + 0.2' => ['0.30', '100', InterestRateType::Monthly, '0.30'],
        ];
    }

    #[DataProvider('roundingBoundaries')]
    public function test_rounding_boundaries(string $base, string $rate, InterestRateType $type, string $expected): void
    {
        $this->assertSame($expected, $this->calculator()->expectedInterest($base, $rate, $type, $this->period()));
    }

    public function test_largest_amounts_stay_exact_where_floats_would_not(): void
    {
        // 9,999,999,999,999,999.99 × 2.5% = 249,999,999,999,999.99975 → 250,000,000,000,000.00
        $this->assertSame(
            '250000000000000.00',
            $this->calculator()->expectedInterest('9999999999999999.99', '2.5', InterestRateType::Monthly, $this->period()),
        );

        // A float cannot even represent the base.
        $this->assertNotSame('9999999999999999.99', sprintf('%.2f', 9999999999999999.99));
    }

    public function test_results_always_have_two_decimals(): void
    {
        foreach (['1', '12.5', '0.0001', '99.9999'] as $rate) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $this->calculator()->expectedInterest('12345.67', $rate, InterestRateType::Yearly, $this->period()));
        }
    }

    public function test_invalid_input_is_rejected(): void
    {
        foreach ([['-1.00', '2'], ['100.00', '-2'], ['abc', '2'], ['100.00', '']] as [$base, $rate]) {
            try {
                $this->calculator()->expectedInterest($base, $rate, InterestRateType::Monthly, $this->period());
                $this->fail("Accepted base [{$base}] rate [{$rate}].");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
