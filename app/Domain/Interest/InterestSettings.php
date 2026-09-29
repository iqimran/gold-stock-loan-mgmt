<?php

namespace App\Domain\Interest;

use App\Enums\InterestBase;
use App\Enums\InterestDueTiming;
use App\Enums\YearlyRateConversion;

/**
 * The configurable interest rules, in one place (config/loans.php today; the Settings module later).
 * Defaults were decided by the business: reducing balance, due at the period end, yearly rate ÷ 12.
 */
final readonly class InterestSettings
{
    public function __construct(
        public InterestBase $base = InterestBase::Outstanding,
        public InterestDueTiming $due = InterestDueTiming::PeriodEnd,
        public YearlyRateConversion $yearlyConversion = YearlyRateConversion::Twelfths,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            InterestBase::from((string) config('loans.interest.base', InterestBase::Outstanding->value)),
            InterestDueTiming::from((string) config('loans.interest.due', InterestDueTiming::PeriodEnd->value)),
            YearlyRateConversion::from((string) config('loans.interest.yearly_conversion', YearlyRateConversion::Twelfths->value)),
        );
    }
}
