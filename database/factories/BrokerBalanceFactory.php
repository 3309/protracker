<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BrokerBalance;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrokerBalance>
 */
class BrokerBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'as_of_date' => fake()->dateTimeBetween('2026-01-05', '2026-06-30')->format('Y-m-d'),
            'total_cash' => Money::fromCents(fake()->numberBetween(0, 5_000_000)),
        ];
    }
}
