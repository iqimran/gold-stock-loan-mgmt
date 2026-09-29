<?php

namespace App\Domain\Payment;

use App\Support\Money;

/**
 * The result of allocating one payment (decimal strings).
 */
final readonly class PaymentAllocation
{
    /**
     * @param  array<int, string>  $periods  interest period id => interest applied, oldest first
     */
    public function __construct(
        public array $periods,
        public string $principal,
        public string $fee,
    ) {}

    public function interest(): string
    {
        return array_reduce($this->periods, fn (string $sum, string $amount) => Money::add($sum, $amount), '0.00');
    }

    public function total(): string
    {
        return Money::add($this->interest(), $this->principal, $this->fee);
    }
}
