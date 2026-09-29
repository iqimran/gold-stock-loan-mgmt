<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_no' => 'CUS-'.now()->format('Ym').'-'.fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'mobile' => fake()->unique()->numerify('017########'),
            'nid' => fake()->optional()->numerify('##########'),
            'address' => fake()->optional()->address(),
            'status' => CustomerStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => CustomerStatus::Archived]);
    }
}
