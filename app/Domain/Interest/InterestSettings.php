<?php

namespace App\Domain\Interest;

use App\Enums\InterestBase;
use App\Enums\InterestDueTiming;
use App\Enums\YearlyRateConversion;
use App\Models\Loan;

/**
 * An interest method: base (reducing/flat), due timing and yearly-rate conversion.
 *
 * Each loan carries its own method, copied from Settings when the loan is created (business-decided:
 * a settings change affects new loans only). The container binding resolves the method for NEW loans
 * from App\Domain\Settings\LoanSettings; forLoan() gives the method a loan actually runs under.
 */
final readonly class InterestSettings
{
    public function __construct(
        public InterestBase $base = InterestBase::Outstanding,
        public InterestDueTiming $due = InterestDueTiming::PeriodEnd,
        public YearlyRateConversion $yearlyConversion = YearlyRateConversion::Twelfths,
    ) {}

    /**
     * The method stored on the loan. Every loan gets one when it is inserted (Loan::booted) and the
     * migration back-filled older rows, so the fallback — the config default the engine used before
     * Settings existed, never the current Settings — is only a safety net.
     */
    public static function forLoan(Loan $loan): self
    {
        $defaults = new self(
            InterestBase::tryFrom((string) config('loans.interest.base')) ?? InterestBase::Outstanding,
            InterestDueTiming::tryFrom((string) config('loans.interest.due')) ?? InterestDueTiming::PeriodEnd,
            YearlyRateConversion::tryFrom((string) config('loans.interest.yearly_conversion')) ?? YearlyRateConversion::Twelfths,
        );

        return new self(
            InterestBase::tryFrom((string) $loan->interest_base) ?? $defaults->base,
            InterestDueTiming::tryFrom((string) $loan->interest_due_timing) ?? $defaults->due,
            YearlyRateConversion::tryFrom((string) $loan->yearly_rate_conversion) ?? $defaults->yearlyConversion,
        );
    }

    /**
     * @return array{interest_base: string, interest_due_timing: string, yearly_rate_conversion: string}
     */
    public function toLoanAttributes(): array
    {
        return [
            'interest_base' => $this->base->value,
            'interest_due_timing' => $this->due->value,
            'yearly_rate_conversion' => $this->yearlyConversion->value,
        ];
    }
}
