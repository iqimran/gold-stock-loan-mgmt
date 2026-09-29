<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Loan;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare payment row for fixtures (no allocation or ledger). Real payments go through PaymentService.
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_no' => 'RCPT-'.now()->format('Ym').'-'.fake()->unique()->numerify('######'),
            'loan_id' => Loan::factory()->active(),
            'customer_id' => fn (array $attributes) => Loan::find($attributes['loan_id'])->customer_id,
            'type' => PaymentType::Interest,
            'amount' => '200.00',
            'method' => 'cash',
            'payment_date' => '2026-10-31',
            'status' => PaymentStatus::Posted,
        ];
    }
}
