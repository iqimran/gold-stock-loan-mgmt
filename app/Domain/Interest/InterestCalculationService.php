<?php

namespace App\Domain\Interest;

use App\Enums\InterestRateType;
use App\Enums\YearlyRateConversion;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Expected interest for one period (docs/07 "InterestCalculationService").
 *
 * Exact decimal arithmetic (bcmath) end to end; the result is rounded exactly once, by the
 * application's single rounding policy (App\Support\Money: half away from zero, 2 decimals).
 * The whole fraction is formed before dividing, so no intermediate rounding can move a result
 * across a rounding boundary.
 */
class InterestCalculationService
{
    /** Intermediate precision; far beyond the 2 decimals kept, so truncation never changes the rounded result. */
    private const SCALE = 12;

    public function __construct(private readonly InterestSettings $settings = new InterestSettings) {}

    /**
     * The period's rate as a percentage (e.g. "2.000000000000" for 24% yearly ÷ 12).
     */
    public function periodRate(string $rate, InterestRateType $type, InterestPeriodBounds $period): string
    {
        [$numerator, $denominator] = $this->rateFraction($rate, $type, $period);

        return bcdiv($numerator, $denominator, self::SCALE);
    }

    /**
     * base × period rate ÷ 100, rounded once to 2 decimals.
     *
     * @param  string  $base  principal the period's interest is charged on (decimal string)
     * @param  string  $rate  the loan's interest rate, a percentage (decimal string)
     */
    public function expectedInterest(string $base, string $rate, InterestRateType $type, InterestPeriodBounds $period): string
    {
        foreach (['base' => $base, 'rate' => $rate] as $name => $value) {
            if (! is_numeric($value) || bccomp($value, '0', self::SCALE) < 0) {
                throw new InvalidArgumentException("Interest {$name} must be a non-negative decimal, [{$value}] given.");
            }
        }

        [$numerator, $denominator] = $this->rateFraction($rate, $type, $period);

        return Money::round(bcdiv(bcmul($base, $numerator, self::SCALE), bcmul($denominator, '100', 0), self::SCALE));
    }

    /**
     * The period rate as an exact fraction [numerator, denominator] of percentages.
     *
     * @return array{0: string, 1: string}
     */
    private function rateFraction(string $rate, InterestRateType $type, InterestPeriodBounds $period): array
    {
        return match ($type) {
            // Periods are months (InterestPeriodUnit::Month): a monthly rate applies as is.
            InterestRateType::Monthly => [$rate, '1'],
            InterestRateType::Yearly => match ($this->settings->yearlyConversion) {
                YearlyRateConversion::Twelfths => [$rate, '12'],
                YearlyRateConversion::ActualDays => [bcmul($rate, (string) $period->days(), self::SCALE), '365'],
            },
        };
    }
}
