<?php

namespace Database\Factories;

use App\Enums\InterestPeriodUnit;
use App\Enums\InterestRateType;
use App\Enums\LoanStatus;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loan_no' => 'LN-'.now()->format('Ym').'-'.fake()->unique()->numerify('######'),
            'customer_id' => Customer::factory(),
            'principal' => '50000.00',
            'outstanding_principal' => '50000.00',
            'interest_rate' => '2.5000',
            'interest_rate_type' => InterestRateType::Monthly,
            'interest_period_unit' => InterestPeriodUnit::Month,
            'status' => LoanStatus::Draft,
            'start_date' => '2026-10-01',
        ];
    }

    public function status(LoanStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function active(): static
    {
        return $this->status(LoanStatus::Active);
    }
}
