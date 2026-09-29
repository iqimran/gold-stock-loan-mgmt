<?php

namespace App\Domain\Payment;

use App\Enums\PaymentType;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Splits a payment into interest (per period), principal and fee — deterministically (docs/08) and by
 * the business-decided rules:
 *
 *   interest                 oldest unpaid period first (by due date), up to the interest payable
 *   principal                reduces outstanding principal, up to what is outstanding
 *   principal_and_interest   clears interest payable (oldest first), the remainder reduces principal
 *   other_fee                a fee collected at the counter; touches neither interest nor principal
 *   adjustment               refused until an adjustment workflow is specified
 *
 * Overpayment is never accepted (docs/08 Payments 2). Pure calculation on decimal strings.
 */
final class PaymentAllocator
{
    /**
     * @param  list<array{id: int, expected: string, paid: string}>  $openPeriods  unpaid, non-waived periods, oldest due first
     */
    public function allocate(PaymentType $type, string $amount, array $openPeriods, string $outstandingPrincipal): PaymentAllocation
    {
        $amount = Money::of($amount);

        if (! Money::isPositive($amount)) {
            throw ValidationException::withMessages(['amount' => 'The payment amount must be greater than zero.']);
        }

        $interestPayable = array_reduce($openPeriods, fn (string $sum, array $period) => Money::add($sum, $this->unpaid($period)), '0.00');

        return match ($type) {
            PaymentType::Interest => $this->interestOnly($amount, $openPeriods, $interestPayable),
            PaymentType::Principal => $this->principalOnly($amount, $outstandingPrincipal),
            PaymentType::PrincipalAndInterest => $this->combined($amount, $openPeriods, $interestPayable, $outstandingPrincipal),
            PaymentType::OtherFee => new PaymentAllocation([], '0.00', $amount),
            PaymentType::Adjustment => throw ValidationException::withMessages([
                'type' => 'Adjustments are not accepted yet: an adjustment workflow has not been specified.',
            ]),
        };
    }

    /**
     * @param  list<array{id: int, expected: string, paid: string}>  $openPeriods
     */
    private function interestOnly(string $amount, array $openPeriods, string $interestPayable): PaymentAllocation
    {
        if (Money::cmp($amount, $interestPayable) > 0) {
            throw ValidationException::withMessages([
                'amount' => "The amount exceeds the interest payable ({$interestPayable}).",
            ]);
        }

        return new PaymentAllocation($this->toPeriods($amount, $openPeriods), '0.00', '0.00');
    }

    private function principalOnly(string $amount, string $outstandingPrincipal): PaymentAllocation
    {
        if (Money::cmp($amount, $outstandingPrincipal) > 0) {
            throw ValidationException::withMessages([
                'amount' => "The amount exceeds the outstanding principal ({$outstandingPrincipal}).",
            ]);
        }

        return new PaymentAllocation([], $amount, '0.00');
    }

    /**
     * @param  list<array{id: int, expected: string, paid: string}>  $openPeriods
     */
    private function combined(string $amount, array $openPeriods, string $interestPayable, string $outstandingPrincipal): PaymentAllocation
    {
        $maximum = Money::add($interestPayable, $outstandingPrincipal);

        if (Money::cmp($amount, $maximum) > 0) {
            throw ValidationException::withMessages([
                'amount' => "The amount exceeds the interest payable plus outstanding principal ({$maximum}).",
            ]);
        }

        $interest = Money::min($amount, $interestPayable);

        return new PaymentAllocation($this->toPeriods($interest, $openPeriods), Money::sub($amount, $interest), '0.00');
    }

    /**
     * Oldest period first until the amount is used up.
     *
     * @param  list<array{id: int, expected: string, paid: string}>  $openPeriods
     * @return array<int, string> period id => interest applied
     */
    private function toPeriods(string $amount, array $openPeriods): array
    {
        $applied = [];
        $left = $amount;

        foreach ($openPeriods as $period) {
            if (! Money::isPositive($left)) {
                break;
            }

            $share = Money::min($left, $this->unpaid($period));

            if (Money::isPositive($share)) {
                $applied[$period['id']] = $share;
                $left = Money::sub($left, $share);
            }
        }

        return $applied;
    }

    /**
     * @param  array{expected: string, paid: string}  $period
     */
    private function unpaid(array $period): string
    {
        return Money::max(Money::sub($period['expected'], $period['paid']), '0.00');
    }
}
