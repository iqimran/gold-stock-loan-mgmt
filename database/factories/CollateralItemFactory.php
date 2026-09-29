<?php

namespace Database\Factories;

use App\Enums\CollateralStatus;
use App\Models\CollateralItem;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollateralItem>
 */
class CollateralItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'collateral_no' => 'COL-'.now()->format('Ym').'-'.fake()->unique()->numerify('######'),
            'loan_id' => Loan::factory(),
            'type' => 'gold',
            'weight_grams' => '11.664',
            'karat' => '22.00',
            'estimated_value' => '90000.00',
            'description' => 'Gold bangle',
            'status' => CollateralStatus::Held,
            'received_at' => '2026-10-01 10:00:00',
        ];
    }
}
